import { readFileSync, mkdirSync } from "node:fs";
import { expect, test } from "@playwright/test";

const configuration = Object.fromEntries(
  readFileSync(".env", "utf8")
    .trim()
    .split("\n")
    .map((line) => line.split("=", 2)),
);

test("native payment backend, encrypted setup and return refresh", async ({ browser }) => {
  mkdirSync("test-results/screenshots", { recursive: true });
  const context = await browser.newContext({ colorScheme: "light" });
  const page = await context.newPage();
  await page.goto("/contao/login");
  await page.locator("#username").fill(configuration.CONTAO_ADMIN_EMAIL);
  await page.locator("#password").fill(configuration.CONTAO_ADMIN_PASSWORD);
  await page.locator("#login").click();
  await page.goto("/contao?do=nw_payments");
  await expect(
    page.getByRole("heading", { name: "Zahlungen", level: 2, exact: true }),
  ).toBeVisible();
  await page.screenshot({
    path: "test-results/screenshots/backend-payments-light.png",
    fullPage: true,
  });
  await page.emulateMedia({ colorScheme: "dark" });
  await expect(page.locator("html")).toHaveAttribute("data-color-scheme", "dark");
  await page.screenshot({
    path: "test-results/screenshots/backend-payments-dark.png",
    fullPage: true,
  });
  await page.goto("/contao?do=nw_payment_settings");
  await page.getByLabel("Secret Key", { exact: true }).fill("sk_test_local_fixture");
  await page.getByRole("button", { name: "Speichern", exact: true }).click();
  await expect(page.getByText("Einstellungen gespeichert.")).toBeVisible();
  await expect(page.getByLabel("Secret Key", { exact: true })).toHaveValue("");
  await page.getByRole("button", { name: "Webhook bei Stripe anlegen" }).click();
  await expect(
    page.getByText("Webhook bei Stripe angelegt und Secret verschlüsselt gespeichert."),
  ).toBeVisible();
  await expect(page.getByLabel("Webhook-Secret", { exact: true })).toHaveValue("");
  await page.screenshot({ path: "test-results/screenshots/settings.png", fullPage: true });
  await page.goto("/_nw/payment/return/" + "a".repeat(64));
  await expect(page.getByRole("heading", { name: "Zahlung wird geprüft" })).toBeVisible();
  await expect(page.locator('meta[http-equiv="refresh"]')).toHaveAttribute("content", "5");
  await page.screenshot({ path: "test-results/screenshots/return.png", fullPage: true });
  await context.close();
});
