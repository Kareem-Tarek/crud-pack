# CRUD Pack

**CRUD Pack** is a reusable Laravel CRUD generator package that allows you to scaffold complete CRUD resources (Web or API) using a single Artisan command, while strictly following Laravel conventions and keeping your application clean, consistent, and low-code.

The package always generates a controller and can optionally generate models, migrations, request validation, policies, Blade views (Web only), and routes. It includes first-class support for **soft deletes**, **bulk operations**, **deleted listings**, **restore workflows**, and **force-delete workflows**, all implemented in a shared, reusable way.

CRUD Pack is intentionally designed as a **blueprint generator**, not a black box. Everything it generates is readable, editable, and meant to be customized by the developer. Nothing is hidden, auto-magical, or locked behind abstractions.

---

## Requirements

- **PHP:** 8.0 or higher
- **Laravel:** 8.x and above  
  - Developed and tested on **Laravel 11 and 12**
  - Fully compatible with Laravel 8+

---

## Installation

```bash
composer require kareemtarek/crud-pack
