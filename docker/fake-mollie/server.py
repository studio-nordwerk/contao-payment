"""Disposable Mollie protocol fixture. Never use outside the local Compose network. Public test data only."""
import html, ipaddress, json, os, re, threading, time, urllib.parse, urllib.request, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

payments, refunds, keys = {}, {}, {}
refund_mode = {'mode': 'refunded'}
lock = threading.RLock()
public = os.environ.get('FAKE_MOLLIE_PUBLIC_URL', 'http://127.0.0.1:8096')
AMOUNT = re.compile(r'^(0|[1-9][0-9]*)\.[0-9]{2}$')

def cents(amount):
    whole, frac = amount['value'].split('.')
    return int(whole) * 100 + int(frac)

def money(value, currency):
    return {'currency': currency, 'value': '%d.%02d' % (value // 100, value % 100)}

def public_host(url):
    # Mollie rejects webhook URLs it cannot reach; so does the fixture.
    host = (urllib.parse.urlsplit(url).hostname or '').lower()
    if not host or host == 'localhost' or host.endswith(('.localhost', '.test', '.local')) or '.' not in host and ':' not in host:
        return False
    try:
        address = ipaddress.ip_address(host)
        return address.is_global
    except ValueError:
        return True

def view(payment):
    out = {k: v for k, v in payment.items() if not k.startswith('_')}
    out['resource'] = 'payment'
    done = sum(cents(r['amount']) for r in refunds.values() if r['paymentId'] == payment['id'] and r['status'] == 'refunded')
    if any(r['paymentId'] == payment['id'] for r in refunds.values()):
        out['amountRefunded'] = money(done, payment['amount']['currency'])
        out['amountRemaining'] = money(cents(payment['amount']) - done, payment['amount']['currency'])
    links = {'self': {'href': public + '/v2/payments/' + payment['id'], 'type': 'application/hal+json'}}
    if payment['status'] == 'open':
        links['checkout'] = {'href': public + '/checkout/' + payment['id'], 'type': 'text/html'}
    out['_links'] = links
    return out

def call_webhook(payment):
    url = payment.get('webhookUrl')
    if not url:
        return
    body = urllib.parse.urlencode({'id': payment['id']}).encode()
    request = urllib.request.Request(url, body, {'Content-Type': 'application/x-www-form-urlencoded'})
    for attempt in range(5):
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                if response.status < 300:
                    return
        except Exception:
            time.sleep(0.2 * (attempt + 1))

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def reply(self, value, status=200, mime='application/json'):
        body = (json.dumps(value) if mime == 'application/json' else value).encode()
        self.send_response(status)
        self.send_header('Content-Type', mime)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def error(self, status, detail):
        self.reply({'status': status, 'title': 'Error', 'detail': detail}, status)

    def authorized(self):
        return re.match(r'^Bearer test_[A-Za-z0-9_]+$', self.headers.get('Authorization', '')) is not None

    def do_GET(self):
        path = urllib.parse.urlsplit(self.path).path
        with lock:
            if path.startswith('/v2/'):
                if not self.authorized():
                    self.error(401, 'Missing or invalid test API key'); return
                parts = path.split('/')[2:]
                payment = payments.get(parts[1]) if len(parts) > 1 and parts[0] == 'payments' else None
                if payment is None:
                    self.error(404, 'No payment exists with this token'); return
                if len(parts) == 2:
                    self.reply(view(payment))
                elif len(parts) == 3 and parts[2] == 'refunds':
                    items = [r for r in refunds.values() if r['paymentId'] == payment['id']]
                    self.reply({'count': len(items), '_embedded': {'refunds': items}})
                elif len(parts) == 4 and parts[2] == 'refunds' and parts[3] in refunds and refunds[parts[3]]['paymentId'] == payment['id']:
                    self.reply(refunds[parts[3]])
                else:
                    self.error(404, 'Not found')
            elif path.startswith('/checkout/'):
                payment = payments.get(path.rsplit('/', 1)[1])
                if not payment:
                    self.reply('Not found', 404, 'text/plain'); return
                ident = html.escape(payment['id'])
                self.reply('<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Fake-Mollie</title></head><body><main><h1>Fake-Mollie Checkout</h1><p>Lokaler Test ohne echte Zahlung</p><p>' + html.escape(payment['amount']['value']) + ' ' + html.escape(payment['amount']['currency']) + '</p><form method="post" action="/checkout/' + ident + '"><button name="action" value="pay">Bezahlen</button><button name="action" value="cancel">Abbrechen</button><button name="action" value="expire">Ablaufen lassen</button></form></main></body></html>', mime='text/html')
            else:
                self.error(404, 'Not found')

    def do_POST(self):
        path = urllib.parse.urlsplit(self.path).path
        raw = self.rfile.read(int(self.headers.get('Content-Length', 0))).decode()
        with lock:
            if path.startswith('/checkout/'):
                form = urllib.parse.parse_qs(raw, keep_blank_values=True)
                payment = payments.get(path.rsplit('/', 1)[1])
                if not payment:
                    self.reply({}, 404); return
                action = form.get('action', [''])[0]
                if payment['status'] == 'open':
                    if action == 'pay':
                        payment['status'] = 'paid'; payment['paidAt'] = time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime())
                    elif action == 'cancel':
                        payment['status'] = 'canceled'
                    elif action == 'expire':
                        payment['status'] = 'expired'
                target = payment.get('cancelUrl') if action == 'cancel' and payment.get('cancelUrl') else payment['redirectUrl']
                self.send_response(303); self.send_header('Location', target); self.end_headers()
                threading.Thread(target=call_webhook, args=(dict(payment),), daemon=True).start()
                return
            try:
                data = json.loads(raw or '{}')
            except ValueError:
                self.error(400, 'Invalid JSON'); return
            if path == '/_fixture/refund-mode':
                refund_mode['mode'] = 'pending' if data.get('mode') == 'pending' else 'refunded'
                self.reply({'ok': True}); return
            if path.startswith('/_fixture/refunds/') and path.endswith('/settle'):
                refund = refunds.get(path.split('/')[3])
                if not refund: self.reply({}, 404); return
                refund['status'] = data.get('status', 'refunded')
                self.reply(refund); return
            if not self.authorized():
                self.error(401, 'Missing or invalid test API key'); return
            key = self.headers.get('Idempotency-Key')
            if key and key in keys:
                self.reply(keys[key]); return
            if path == '/v2/payments':
                amount = data.get('amount') or {}
                if not isinstance(amount.get('value'), str) or not AMOUNT.match(amount['value']) or not re.match(r'^[A-Z]{3}$', str(amount.get('currency', ''))) or cents(amount) < 1:
                    self.error(422, 'The amount is invalid'); return
                if not data.get('description') or not data.get('redirectUrl'):
                    self.error(422, 'description and redirectUrl are required'); return
                if data.get('webhookUrl') and not public_host(data['webhookUrl']):
                    self.error(422, 'The webhook URL is invalid because it is unreachable'); return
                ident = 'tr_' + uuid.uuid4().hex[:10]
                payment = {'id': ident, 'mode': 'test', 'status': 'open', 'amount': amount, 'description': data['description'], 'metadata': data.get('metadata'), 'redirectUrl': data['redirectUrl'], 'createdAt': time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime())}
                for field in ('cancelUrl', 'webhookUrl'):
                    if data.get(field):
                        payment[field] = data[field]
                payments[ident] = payment
                result = view(payment)
                if key: keys[key] = result
                self.reply(result, 201)
                return
            match = re.match(r'^/v2/payments/([^/]+)/refunds$', path)
            if match:
                payment = payments.get(match.group(1))
                if not payment:
                    self.error(404, 'No payment exists with this token'); return
                amount = data.get('amount') or {}
                done = sum(cents(r['amount']) for r in refunds.values() if r['paymentId'] == payment['id'] and r['status'] != 'failed' and r['status'] != 'canceled')
                if payment['status'] != 'paid' or not isinstance(amount.get('value'), str) or not AMOUNT.match(amount['value']) or amount.get('currency') != payment['amount']['currency'] or cents(amount) < 1 or cents(amount) > cents(payment['amount']) - done:
                    self.error(422, 'The refund is invalid'); return
                ident = 're_' + uuid.uuid4().hex[:10]
                refund = {'resource': 'refund', 'id': ident, 'paymentId': payment['id'], 'amount': amount, 'status': 'pending' if refund_mode['mode'] == 'pending' else 'refunded', 'description': data.get('description', ''), 'metadata': data.get('metadata')}
                refunds[ident] = refund
                if key: keys[key] = refund
                self.reply(refund, 201)
                threading.Thread(target=call_webhook, args=(dict(payment),), daemon=True).start()
                return
            self.error(404, 'Not found')

ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()
