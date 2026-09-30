"""Disposable Stripe protocol fixture. Never use outside the local Compose network."""
import hashlib, hmac, html, json, os, threading, time, urllib.parse, urllib.request, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sessions, keys, events = {}, {}, {}
timeout_payments = set()
lock = threading.RLock()
secret = os.environ.get('FAKE_STRIPE_WEBHOOK_SECRET', 'whsec_local_fixture')
webhook = os.environ.get('FAKE_STRIPE_WEBHOOK_URL', 'http://caddy/_nw/payment/webhook/stripe')
public = os.environ.get('FAKE_STRIPE_PUBLIC_URL', 'http://127.0.0.1:8094')

def send(event):
    raw = json.dumps(event, separators=(',', ':')).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(secret.encode(), stamp.encode()+b'.'+raw, hashlib.sha256).hexdigest()
    req = urllib.request.Request(webhook, raw, {'Content-Type': 'application/json', 'Stripe-Signature': 't='+stamp+',v1='+signature})
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req, timeout=15) as response:
                if response.status < 300:
                    return
        except Exception:
            time.sleep(0.2 * (attempt + 1))

def event(session, kind):
    obj = dict(session)
    if kind == 'charge.refunded':
        obj = {'id': 'ch_'+session['id'], 'object': 'charge', 'amount': session['amount_total'], 'amount_refunded': session.get('amount_refunded', 0), 'currency': session['currency'], 'payment_intent': session['payment_intent']}
    e = {'id': 'evt_'+uuid.uuid4().hex, 'object': 'event', 'type': kind, 'livemode': False, 'data': {'object': obj}}
    events[e['id']] = e
    return e

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

    def do_GET(self):
        path = urllib.parse.urlsplit(self.path)
        query = urllib.parse.parse_qs(path.query)
        with lock:
            if path.path == '/v1/checkout/sessions':
                self.reply({'object': 'list', 'data': [s for s in sessions.values() if s['payment_intent'] == query.get('payment_intent', [''])[0]], 'has_more': False})
            elif path.path.startswith('/v1/checkout/sessions/'):
                s = sessions.get(path.path.rsplit('/',1)[1])
                self.reply(s or {'error': {'message': 'Not found'}}, 200 if s else 404)
            elif path.path.startswith('/_fixture/session/'):
                self.reply(sessions.get(path.path.rsplit('/',1)[1], {}))
            elif path.path == '/_fixture/events':
                self.reply(list(events.values()))
            elif path.path.startswith('/checkout/'):
                s = sessions.get(path.path.rsplit('/',1)[1])
                if not s:
                    self.reply('Not found',404,'text/plain'); return
                ident = html.escape(s['id'])
                self.reply('<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Fake-Stripe</title></head><body><main><h1>Fake-Stripe Checkout</h1><p>Lokaler Test ohne echte Zahlung</p><p>'+str(s['amount_total'])+' Cent '+html.escape(s['currency'].upper())+'</p><form method="post" action="/checkout/'+ident+'"><button name="action" value="pay">Bezahlen</button><button name="action" value="cancel">Abbrechen</button><button name="action" value="expire">Session ablaufen lassen</button></form></main></body></html>', mime='text/html')
            else:
                self.reply({'error': {'message': 'Not found'}}, 404)

    def do_POST(self):
        path = urllib.parse.urlsplit(self.path).path
        data = urllib.parse.parse_qs(self.rfile.read(int(self.headers.get('Content-Length',0))).decode(), keep_blank_values=True)
        def field(name, default=''):
            return data.get(name,[default])[0]
        key = self.headers.get('Idempotency-Key')
        with lock:
            if path.startswith('/v1/') and key and key in keys:
                self.reply(keys[key]); return
            if path == '/v1/checkout/sessions':
                # Reject imaginary Session parameters so the fixture catches API-shape bugs.
                if 'automatic_payment_methods[enabled]' in data or field('mode') != 'payment':
                    self.reply({'error': {'message':'Invalid Session parameters'}},400); return
                ident = 'cs_test_'+uuid.uuid4().hex
                s = {'id':ident,'object':'checkout.session','livemode':False,'status':'open','payment_status':'unpaid','amount_total':int(field('line_items[0][price_data][unit_amount]')),'currency':field('line_items[0][price_data][currency]'),'metadata':{'payment_id':field('metadata[payment_id]')},'payment_intent':'pi_'+uuid.uuid4().hex,'url':public+'/checkout/'+ident,'success_url':field('success_url'),'cancel_url':field('cancel_url')}
                sessions[ident] = s
                if key: keys[key] = s
                self.reply(s)
            elif path == '/v1/webhook_endpoints':
                result={'id':'we_'+uuid.uuid4().hex,'object':'webhook_endpoint','livemode':False,'secret':secret}
                self.reply(result)
            elif path == '/_fixture/refund-timeout':
                timeout_payments.add(field('payment_id'))
                self.reply({'ok':True})
            elif path == '/v1/refunds':
                s = next((s for s in sessions.values() if s['payment_intent']==field('payment_intent')),None)
                amount=int(field('amount','0'))
                if not s or s['payment_status'] != 'paid' or amount < 1 or amount > s['amount_total']-s.get('amount_refunded',0):
                    self.reply({'error':{'message':'Invalid refund'}},400); return
                s['amount_refunded']=s.get('amount_refunded',0)+amount
                result={'id':'re_'+uuid.uuid4().hex,'object':'refund','status':'succeeded','amount':amount}
                if key: keys[key]=result
                e=event(s,'charge.refunded')
                if field('metadata[payment_id]') in timeout_payments:
                    timeout_payments.remove(field('metadata[payment_id]'))
                    # Money moved, but the HTTP response is lost.
                    self.close_connection = True
                    self.connection.shutdown(2)
                    self.connection.close()
                else:
                    self.reply(result)
                threading.Thread(target=send,args=(e,),daemon=True).start()
            elif path.startswith('/checkout/'):
                s=sessions.get(path.rsplit('/',1)[1])
                if not s: self.reply({},404); return
                action=field('action')
                if action == 'pay':
                    s['status']='complete';s['payment_status']='paid';e=event(s,'checkout.session.completed')
                elif action == 'expire':
                    s['status']='expired';e=event(s,'checkout.session.expired')
                else: e=None
                self.send_response(303);self.send_header('Location',s['cancel_url'] if action=='cancel' else s['success_url']);self.end_headers()
                if e: threading.Thread(target=send,args=(e,),daemon=True).start()
            elif path == '/_fixture/replay':
                e=events.get(field('event_id'))
                if not e: self.reply({},404); return
                self.reply({'ok':True});threading.Thread(target=send,args=(e,),daemon=True).start()
            else:
                self.reply({'error':{'message':'Not found'}},404)

ThreadingHTTPServer(('0.0.0.0',8080), Handler).serve_forever()
