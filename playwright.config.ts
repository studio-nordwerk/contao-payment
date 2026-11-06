import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  workers: 1,
  reporter: "list",
  use: { launchOptions: { chromiumSandbox: !process.env.CI }, baseURL: "http://127.0.0.1:8084" },
});
