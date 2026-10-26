import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { expect, test, type Page } from "@playwright/test";

const configuration = Object.fromEntries(
  readFileSync(".env", "utf8")
    .trim()
    .split("\n")
    .map((line) => line.split("=", 2)),
);

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

// Field permissions ("alexf") exist only on user groups, so each audit user gets its own group.
function createUser(username: string, modules: string[], cud: string[], fields: string[]): void {
  const list = (values: string[]) => values.map((value) => `'${value}'`).join(",");
  const setup = `require 'vendor/autoload.php';
    $db = \\Doctrine\\DBAL\\DriverManager::getConnection(['driver'=>'pdo_mysql','host'=>'db','user'=>'contao','password'=>'contao','dbname'=>'contao']);
    $db->delete('tl_user', ['username'=>'${username}']);
    $db->delete('tl_user_group', ['name'=>'${username}']);
    $db->insert('tl_user_group', ['name'=>'${username}','modules'=>serialize([${list(modules)}]),'cud'=>serialize([${list(cud)}]),'alexf'=>serialize([${list(fields)}]),'tstamp'=>time()]);
    $group = (int) $db->lastInsertId();
    $db->insert('tl_user', ['username'=>'${username}','name'=>'Settings audit','email'=>'${username}@example.test','language'=>'de','password'=>password_hash(getenv('CONTAO_ADMIN_PASSWORD'), PASSWORD_BCRYPT),'groups'=>serialize([(string) $group]),'inherit'=>'group','tstamp'=>time(),'dateAdded'=>time()]);`;
  execFileSync("docker", ["compose", "exec", "-T", "php", "php", "-r", setup]);
}

async function login(page: Page, username: string): Promise<void> {
  await page.goto("/contao/login");
  await page.locator("#username").fill(username);
  await page.locator("#password").fill(configuration.CONTAO_ADMIN_PASSWORD);
  await page.locator("#login").click();
  await expect(page.locator("#password")).toHaveCount(0);
}

test.afterAll(() => {
  sql(
    "DELETE FROM tl_user WHERE username IN ('payment-switches', 'payment-module-only', 'payment-bank-only', 'payment-no-update')",
  );
  sql(
    "DELETE FROM tl_user_group WHERE name IN ('payment-switches', 'payment-module-only', 'payment-bank-only', 'payment-no-update')",
  );
});

test("secrets stay out of the form, the show view and the versions", async ({ page }) => {
  await login(page, configuration.CONTAO_ADMIN_EMAIL);
  await page.goto("/contao?do=nw_payment_settings");
  await page.locator("#ctrl_secretKey").fill("sk_test_local_fixture");
  await page.locator("#save").click();
  await expect(page.locator(".tl_error")).toHaveCount(0);
  await expect(page.locator("#ctrl_secretKey")).toHaveValue("");

  // Stripe cannot be switched on without a webhook secret; nothing is stored then.
  await page.locator("#ctrl_webhookSecret").fill("not-a-secret");
  await page.locator("#save").click();
  await expect(page.getByText("Ungültiges Webhook-Secret (beginnt mit whsec_).")).toBeVisible();

  const cipher = sql("SELECT secretKey FROM tl_nw_payment_settings WHERE id = 1");
  expect(cipher).not.toBe("");
  expect(cipher).not.toContain("sk_test");
  expect(
    Number(sql("SELECT COUNT(*) FROM tl_version WHERE fromTable = 'tl_nw_payment_settings'")),
  ).toBeGreaterThan(0);
  expect(
    sql(
      "SELECT COUNT(*) FROM tl_version WHERE fromTable = 'tl_nw_payment_settings' AND (data LIKE '%secretKey%' OR data LIKE '%webhookSecret%')",
    ),
  ).toBe("0");

  await page.goto("/contao?do=nw_payment_settings&act=show&id=1");
  await expect(page.locator("body")).not.toContainText(cipher.slice(0, 24));
  await expect(page.locator("body")).not.toContainText("sk_test_local_fixture");
});

test("switching payment methods can be delegated without the Stripe secrets", async ({ page }) => {
  createUser(
    "payment-switches",
    ["nw_payment_settings"],
    ["tl_nw_payment_settings::update"],
    [
      "tl_nw_payment_settings::bankEnabled",
      "tl_nw_payment_settings::onSiteEnabled",
      "tl_nw_payment_settings::payPalMe",
    ],
  );
  await login(page, "payment-switches");
  await page.goto("/contao?do=nw_payment_settings");
  await expect(page.locator("input[name=onSiteEnabled][type=checkbox]")).toBeVisible();
  await expect(page.locator("#ctrl_secretKey")).toHaveCount(0);
  await expect(page.locator("#ctrl_webhookSecret")).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Webhook bei Stripe anlegen" })).toHaveCount(0);
  await page.locator("#ctrl_payPalMe").fill("paypal.me/hof-laden");
  await page.locator("#save").click();
  await expect(
    page.getByText("Bitte einen PayPal.me-Link wie paypal.me/IhrName eingeben."),
  ).toBeVisible();
  await page.locator("#ctrl_payPalMe").fill("https://paypal.me/hofladen");
  await page.locator("#save").click();
  await expect(page.locator(".tl_error")).toHaveCount(0);
  expect(sql("SELECT payPalMe FROM tl_nw_payment_settings WHERE id = 1")).toBe("hofladen");
  await expect(page.locator("#ctrl_payPalMe")).toHaveValue("paypal.me/hofladen");
  const response = await page.goto("/contao?do=nw_payment_settings&key=webhook");
  expect(response!.status()).toBeGreaterThanOrEqual(400);
  sql("UPDATE tl_nw_payment_settings SET payPalMe = '' WHERE id = 1");
});

test("the module permission alone does not open the settings for editing", async ({ page }) => {
  createUser("payment-module-only", ["nw_payment_settings"], [], []);
  await login(page, "payment-module-only");
  for (const path of [
    "/contao?do=nw_payment_settings&act=edit&id=1",
    "/contao?do=nw_payment_settings&key=webhook",
  ]) {
    const response = await page.goto(path);
    expect(response!.status(), path).toBeGreaterThanOrEqual(400);
    await expect(page.locator("#ctrl_secretKey")).toHaveCount(0);
  }
});

test("restoring a version needs every versioned field, the webhook needs the update permission", async ({
  page,
}) => {
  createUser(
    "payment-bank-only",
    ["nw_payment_settings"],
    ["tl_nw_payment_settings::update"],
    ["tl_nw_payment_settings::bankEnabled"],
  );
  await login(page, "payment-bank-only");
  await page.goto("/contao?do=nw_payment_settings");
  await expect(page.locator("input[name=bankEnabled][type=checkbox]")).toBeVisible();
  const token = await page.locator("input[name=REQUEST_TOKEN]").first().inputValue();
  const restore = await page.request.post("/contao?do=nw_payment_settings&act=edit&id=1", {
    form: { FORM_SUBMIT: "tl_version", REQUEST_TOKEN: token, version: "1" },
  });
  expect(restore.status()).toBeGreaterThanOrEqual(400);
  // In dev the reason is in a header; it must be the version guard, not e.g. the request token.
  expect(
    decodeURIComponent(restore.headers()["x-debug-exception"] ?? "Restoring a version"),
  ).toContain("Restoring a version");

  await page.context().clearCookies();
  createUser(
    "payment-no-update",
    ["nw_payment_settings"],
    [],
    ["tl_nw_payment_settings::webhookSecret"],
  );
  await login(page, "payment-no-update");
  const response = await page.goto("/contao?do=nw_payment_settings&key=webhook");
  expect(response!.status()).toBeGreaterThanOrEqual(400);
  expect(
    decodeURIComponent(response!.headers()["x-debug-exception"] ?? "Stripe webhook"),
  ).toContain("Stripe webhook");
});
