import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

const configuration = Object.fromEntries(
  readFileSync(".env", "utf8")
    .trim()
    .split("\n")
    .map((line) => line.split("=", 2)),
);
const origin = new URL(process.env.PAYMENT_BASE_URL ?? "http://127.0.0.1:8084").origin;

function sql(statement: string): string {
  return execFileSync(
    "docker",
    [
      "compose",
      "exec",
      "-T",
      "db",
      "mariadb",
      "-ucontao",
      "-pcontao",
      "contao",
      "-N",
      "-e",
      statement,
    ],
    { encoding: "utf8" },
  ).trim();
}

// Starts a payment through the same service the shop uses and returns Mollie's checkout page.
function startPayment(payable: string, cents: number): { id: number; redirect: string } {
  const script = `require 'vendor/autoload.php';
    $parts = parse_url((string) getenv('DATABASE_URL'));
    $db = \\Doctrine\\DBAL\\DriverManager::getConnection(['driver'=>'pdo_mysql','host'=>$parts['host'],'user'=>$parts['user'],'password'=>$parts['pass'],'dbname'=>ltrim($parts['path'],'/')]);
    $settings = new \\Nordwerk\\PaymentBundle\\Settings\\PaymentSettings($db, new \\Nordwerk\\PaymentBundle\\Settings\\SecretCipher((string) getenv('APP_SECRET')));
    $routes = new \\Symfony\\Component\\Routing\\RouteCollection(); $routes->add('nw_payment_webhook', new \\Symfony\\Component\\Routing\\Route('/_nw/payment/webhook/{provider}'));
    $mollie = new \\Nordwerk\\PaymentBundle\\Provider\\MollieProvider(new \\Nordwerk\\PaymentBundle\\Provider\\MollieClient($settings, \\Symfony\\Component\\HttpClient\\HttpClient::create()), $settings, new \\Symfony\\Component\\Routing\\Generator\\UrlGenerator($routes, new \\Symfony\\Component\\Routing\\RequestContext('', 'GET', '${new URL(origin).hostname}', 'http')));
    $repository = new \\Nordwerk\\PaymentBundle\\Persistence\\PaymentRepository($db);
    $stripe = new \\Nordwerk\\PaymentBundle\\Provider\\StripeProvider(new \\Nordwerk\\PaymentBundle\\Provider\\StripeClient($settings), $settings);
    $service = new \\Nordwerk\\PaymentBundle\\Persistence\\PaymentService($db, $repository, new \\Nordwerk\\PaymentBundle\\Provider\\BankTransferProvider(), $stripe, $settings, [], new \\Psr\\Log\\NullLogger(), mollie: $mollie);
    $checkout = $service->start('e2e-mollie', '${payable}', new \\Nordwerk\\PaymentBundle\\Domain\\Money(${cents}), 'mollie', '${origin}', 'E2E-Bestellung');
    echo json_encode(['id' => $repository->forPayable('e2e-mollie', '${payable}')->id, 'redirect' => $checkout->redirectUrl]);`;
  return JSON.parse(
    execFileSync("docker", ["compose", "exec", "-T", "php", "php", "-r", script], {
      encoding: "utf8",
    }),
  );
}

test.afterAll(() => {
  // Payments and events stay (the event log is immutable); only the settings are put back.
  sql("UPDATE tl_nw_payment_settings SET mollieEnabled = '', mollieApiKey = NULL WHERE id = 1");
});

test("Mollie: set up in test mode, pay on the Mollie page, refund in the backend", async ({
  page,
}) => {
  await page.goto("/contao/login");
  await page.locator("#username").fill(configuration.CONTAO_ADMIN_EMAIL);
  await page.locator("#password").fill(configuration.CONTAO_ADMIN_PASSWORD);
  await page.locator("#login").click();
  await expect(page.locator("#password")).toHaveCount(0);

  await page.goto("/contao?do=nw_payment_settings");
  await expect(page.locator("#ctrl_mollieApiKey")).toBeVisible();
  // Not switched on without a key: nothing is stored.
  await page.locator("input[name=mollieEnabled][type=checkbox]").check();
  await page.locator("#save").click();
  await expect(
    page.getByText("Mollie lässt sich nur mit gespeichertem API-Key einschalten."),
  ).toBeVisible();
  expect(sql("SELECT COALESCE(mollieEnabled, '') FROM tl_nw_payment_settings WHERE id = 1")).toBe(
    "",
  );

  await page.locator("#ctrl_mollieApiKey").fill("test_local_fixture");
  await page.locator("input[name=mollieEnabled][type=checkbox]").check();
  await page.locator("#save").click();
  await expect(page.locator(".tl_error")).toHaveCount(0);
  await expect(page.locator("#ctrl_mollieApiKey")).toHaveValue("");
  expect(sql("SELECT mollieEnabled FROM tl_nw_payment_settings WHERE id = 1")).toBe("1");
  const stored = sql("SELECT mollieApiKey FROM tl_nw_payment_settings WHERE id = 1");
  expect(stored).not.toBe("");
  expect(stored).not.toContain("test_local_fixture");
  expect(
    sql(
      "SELECT COUNT(*) FROM tl_version WHERE fromTable = 'tl_nw_payment_settings' AND data LIKE '%mollieApiKey%'",
    ),
  ).toBe("0");

  // Paying: the fake Mollie page redirects back; no webhook is reachable from here, so the
  // return page asks Mollie for the state.
  const paid = startPayment("pay-" + Date.now(), 2350);
  expect(sql(`SELECT status FROM tl_nw_payment WHERE id = ${paid.id}`)).toBe("open");
  await page.goto(paid.redirect);
  await page.getByRole("button", { name: "Bezahlen", exact: true }).click();
  await expect(page).toHaveURL(new RegExp("/_nw/payment/return/[a-f0-9]{64}"));
  await expect
    .poll(() => sql(`SELECT status FROM tl_nw_payment WHERE id = ${paid.id}`))
    .toBe("paid");

  // Refund through the backend form.
  await page.goto("/contao?do=nw_payments");
  const form = page.locator(`form:has(#refund_${paid.id})`);
  await form.getByRole("button", { name: "Erstatten" }).click();
  await expect(page.locator(".tl_error")).toHaveCount(0);
  await expect
    .poll(() =>
      sql(`SELECT CONCAT(status, ':', refunded_amount) FROM tl_nw_payment WHERE id = ${paid.id}`),
    )
    .toBe("refunded:2350");
  expect(sql(`SELECT provider FROM tl_nw_payment WHERE id = ${paid.id}`)).toBe("mollie");

  // The customer cancels on the Mollie page: no money moved, the payment ends as expired.
  const canceled = startPayment("cancel-" + Date.now(), 1000);
  await page.goto(canceled.redirect);
  await page.getByRole("button", { name: "Abbrechen", exact: true }).click();
  await expect(page).toHaveURL(/\?canceled=1/);
  await expect
    .poll(() => sql(`SELECT status FROM tl_nw_payment WHERE id = ${canceled.id}`))
    .toBe("expired");
});
