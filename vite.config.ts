import { defineConfig } from "vite-plus";
export default defineConfig({
  fmt: {
    ignorePatterns: [
      "src/**",
      "tests/**",
      "scripts/**",
      "Resources/**",
      "templates/**",
      "config/**",
      "app/**",
      "docker/**",
      "compose.yaml",
    ],
  },
  lint: { options: { typeAware: true, typeCheck: true } },
});
