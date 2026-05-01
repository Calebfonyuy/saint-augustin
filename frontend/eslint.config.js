import pluginVue from 'eslint-plugin-vue'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'

export default defineConfigWithVueTs(
  // ── Ignored paths ──────────────────────────────────────────────────────────
  {
    ignores: [
      'dist/**',
      'coverage/**',
      'node_modules/**',
      '*.config.js',
      '*.config.ts',
      'postcss.config.js',
      'tailwind.config.js',
      // Vite-generated ambient type declarations — uses {} and any intentionally.
      'env.d.ts',
      'vite.config.d.ts',
    ],
  },

  // ── Vue recommended rules (template + <script setup>) ─────────────────────
  pluginVue.configs['flat/recommended'],

  // ── TypeScript recommended rules ───────────────────────────────────────────
  vueTsConfigs.recommended,

  // ── Project-wide overrides ─────────────────────────────────────────────────
  {
    files: ['src/**/*.{ts,vue}'],
    rules: {
      // -- TypeScript ---------------------------------------------------------

      // Prefer `import type` for type-only imports so they are erased at
      // compile time and don't affect bundle output.
      '@typescript-eslint/consistent-type-imports': [
        'error',
        { prefer: 'type-imports', fixStyle: 'inline-type-imports' },
      ],

      // Unused variables are always a bug; leading underscore opts out for
      // intentionally ignored parameters (e.g. error in catch clauses).
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_', caughtErrorsIgnorePattern: '^_' },
      ],

      // `any` is a warning rather than an error to allow pragmatic escape
      // hatches (e.g. third-party payloads) without blocking CI.
      '@typescript-eslint/no-explicit-any': 'warn',

      // Non-null assertions hide genuine null-safety issues; use optional
      // chaining or an explicit check instead.
      '@typescript-eslint/no-non-null-assertion': 'warn',

      // Enforce consistent return types on exported / public functions.
      '@typescript-eslint/explicit-module-boundary-types': 'off',

      // -- Vue ----------------------------------------------------------------

      // All components in this project use PascalCase (AppShell, SlideRenderer,
      // etc.) — enforce in templates too.
      'vue/component-name-in-template-casing': ['error', 'PascalCase'],

      // Enforce the file block order used throughout the codebase:
      // <script setup> first, then <template>, then <style scoped>.
      'vue/block-order': ['error', { order: ['script', 'template', 'style'] }],

      // Macro call order inside <script setup>.
      'vue/define-macros-order': [
        'error',
        { order: ['defineOptions', 'defineProps', 'defineEmits', 'defineSlots'] },
      ],

      // Catch v-for variables that are declared but never used in the template.
      'vue/no-unused-vars': 'error',

      // Prevent referencing undefined components in templates.
      // vue-router's globally-registered components (<router-view>,
      // <router-link>) must be explicitly allowed here.
      'vue/no-undef-components': [
        'error',
        { ignorePatterns: ['RouterView', 'RouterLink', 'router-view', 'router-link'] },
      ],

      // Single-word component names are acceptable for utility/icon components.
      // The multi-word rule is designed to avoid conflicts with HTML elements;
      // project components like Icon, Toast, etc. don't conflict with any
      // standard element so the rule is turned off.
      'vue/multi-word-component-names': 'off',

      // Template formatting — the project uses compact inline-attribute style
      // and Prettier-style formatting is handled separately; turn off rules
      // that would require a full reformat of every template.
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/html-self-closing': 'off',

      // Require a single blank line between top-level tags for readability.
      'vue/padding-line-between-blocks': ['error', 'always'],

      // Disallow unnecessary <template> wrappers with only v-if / v-for.
      'vue/no-useless-template-attributes': 'error',

      // Disallow mutation of props — use emits or a store instead.
      'vue/no-mutating-props': 'error',

      // Prefer `v-bind` shorthand (`:prop` over `v-bind:prop`).
      'vue/v-bind-style': ['error', 'shorthand'],

      // Prefer `v-on` shorthand (`@event` over `v-on:event`).
      'vue/v-on-style': ['error', 'shorthand'],

      // Require `v-bind:key` on elements inside `v-for`.
      'vue/require-v-for-key': 'error',

      // Disallow `v-if` on the same element as `v-for` (ambiguous precedence).
      'vue/no-use-v-if-with-v-for': 'error',

      // Warn on components that could be stateless (no reactive state / emits).
      // Turned off — too noisy for presentational wrappers.
      'vue/no-ref-as-operand': 'error',
    },
  },

  // ── Test file relaxations ──────────────────────────────────────────────────
  {
    files: ['src/**/*.spec.ts'],
    rules: {
      // Test helpers and mocks legitimately use `any`.
      '@typescript-eslint/no-explicit-any': 'off',

      // Inline type assertions are common in test files.
      '@typescript-eslint/no-non-null-assertion': 'off',

      // `as` casts are fine when setting up stubs/mocks.
      '@typescript-eslint/no-unsafe-assignment': 'off',
    },
  },
)
