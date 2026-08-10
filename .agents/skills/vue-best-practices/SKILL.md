---
name: vue-best-practices
description: "Apply this skill whenever writing, reviewing, or refactoring Vue 3 and Quasar frontend code. Covers Composition API, <script setup>, reactivity (ref, reactive, computed), Quasar component usage, Vue Router, state management, event handling, props/emits, and frontend performance."
license: MIT
metadata:
  author: vuejs
---

# Vue 3 & Quasar Best Practices

Rules and standards for building maintainable, high-performance Vue 3 interfaces with Quasar in this application.

## Core Conventions

1. **Use `<script setup>`**: All Vue components should use the `<script setup>` syntax.
2. **Reactivity Primaries**:
   - Use `ref()` for primitive values, strings, numbers, booleans, and arrays.
   - Use `reactive()` for complex state objects with multiple related fields.
   - Use `computed()` for derived values — never mutate reactive state directly inside a computed getter.
3. **Quasar Components**:
   - Prefer Quasar UI components (`q-btn`, `q-dialog`, `q-table`, `q-card`, `q-input`, `q-select`, `q-badge`, `q-avatar`) over raw HTML elements for consistent UI design.
   - Use `$q.notify` for user-facing toasts/notifications.
   - Use `$q.dialog` or `<q-dialog>` for modals and confirmations.
4. **Vue Router**:
   - Register routes cleanly in `frontend/src/router/index.js`.
   - Use named routes or exact paths for navigation: `router.push('/path')` or `router.push({ name: 'RouteName' })`.
   - Keep route parameter types verified.
5. **API Calls**:
   - Use the central API helper (`boot/fetch` or `axios` instance) with proper `try...catch` blocks and user feedback via `$q.notify`.
   - Always handle loading states (`loading.value = true/false`) during asynchronous API operations.
6. **Code Formatting**:
   - Keep template clean, semantic, and accessible.
   - Avoid duplicate function declarations or unused variables.
