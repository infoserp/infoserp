# Menu Master — Hierarchical Menus & Permissions (PostgreSQL + Laravel)

This document explains how the old ERP stores its menus (`user_menu` in the
`infosblank` SQL Server database) and gives a complete design for the new
application. The design keeps the same idea, **Root module → sub menu →
child screen**, built on your existing `menu_master` migration.

### Start here

| If you want to… | Read |
|-----------------|------|
| Understand how the old ERP menus work and what's wrong with them | Sections 1–2 |
| See the new design at a glance | Section 3 |
| **Know which columns to add to your `menu_master` migration** | **Section 4** |
| Create the permission tables (roles, user overrides, company modules, favourites) | Section 5 |
| See example menu data | Section 6 |
| Build the sidebar/top menu and protect routes in Laravel | Section 7 |
| Seed the menus | Section 8 |
| Migrate the old `user_menu` data | Section 9 |
| Check the rules before go-live | Section 10 |

---

## 1. How the Old ERP Does It (`user_menu`)

All facts below were checked against `infosblank` with:

```sql
select * from user_menu where user_id = 'JERO' and parent_code = 'Root'
```

### 1.1 Table structure

| Column | Type | Meaning |
|--------|------|---------|
| `company_code` | `varchar(2)` | Company |
| `branch_code` | `varchar(2)` | Branch |
| `user_id` | `varchar(15)` | **The user this row belongs to.** Every user has a full copy of the menu tree. |
| `menu_code` | `varchar(50)` | Screen code, e.g. `m_txn_salesinvoice` |
| `menu_name` | `varchar(50)` | Caption shown in the menu |
| `menu_name_arabic` | `nvarchar(50)` | Arabic caption (empty) |
| `icon_name` | `nvarchar(50)` | Icon (empty on all rows) |
| `parent_code` | `varchar(50)` | **The parent's `menu_name` (not its `menu_code`).** `Root` for top-level modules. |
| `sort_order` | `varchar(3)` | Display order as text (`001`, `050`, `360`) |
| `menu_level` | `varchar(50)` | Not used (NULL on all rows) |
| `active` | `char(1)` | `Y` = this user can see/open the menu, `N` = hidden. **This flag is the user's permission.** |
| `icon` | `char(1)` | `Y` on 12 frequently used screens (Accounts, Customer, Supplier, Product, Sales Invoice, Purchase Invoice, Receipt Voucher, Payment Voucher…). This appears to be the quick-launch shortcut flag. |

There is no primary key. There are 226 rows, all for user `JERO`, company `01`,
branch `01`.

### 1.2 The tree

There are 3 levels: **Root module → sub menu → screen**.

| Root module (`parent_code = 'Root'`) | `menu_code` | sort | active | Example sub menus |
|--------------------------------------|-------------|------|--------|-------------------|
| Master | `m_master` | 001 | Y | Accounts, Customer, Supplier, Product, Inventory (27 children), Payroll (12)… |
| Transaction | `m_transaction` | 002 | Y | Inventory, Purchase (15), Sales (43), Payment (12), Receipt (3), PDC (4), Journal (8)… |
| Reports | `m_reports` | 003 | Y | Financial Report, Inventory Report, Payroll Report, Personnel Report |
| Admin | `m_admin` | 004 | Y | System Setup, User Setup, Monthly Closing, Year End Closing… |
| Utility | `m_utility` | 005 | **N** | — |

Example path: `Transaction > Sales > Sales Invoice`.

The PHP code in this repository (`includes/erp_user_access.php`,
`erp_load_active_user_menus()`) rebuilds the tree by matching `parent_code`
against `menu_name`, and only shows a menu when its whole parent chain is
active.

### 1.3 Problems found

| # | Problem | Evidence |
|---|---------|----------|
| 1 | **The parent is linked by name, not by code.** Renaming a menu breaks all its children. | `parent_code` = `'Sales'`, `'Master'`, … |
| 2 | **Duplicate names make the parent ambiguous.** A child of "Inventory" can't tell whether it belongs to *Master > Inventory* or *Transaction > Inventory*. | 5 duplicated names: `Inventory` (`m_ma-inventory`, `m_txn_inventory`), `Payroll`, `Personnel`, `Production`, `Schedule`. The PHP code needs a hard-coded `$parentOverrides = ['inventory' => 'm_txn_inventory']`. |
| 3 | **Broken link:** the root is named `Reports`, but its children point to `Report`. All report menus are orphaned. | `m_rp-financial`, `m_rp-inventory`, `m_rp-pay`, `m_rp-per` have `parent_code = 'Report'`. |
| 4 | **Structure and permissions are mixed in one table, copied for every user, company and branch.** 300 menus × 50 users × 3 branches = 45,000 rows. A new screen must be inserted for every user. | `user_id`, `company_code`, `branch_code` are part of every row. |
| 5 | **Only on/off permission.** There is no separate add / edit / delete / print / approve right. | Only `active`. |
| 6 | **No route/URL column.** The link from menu to screen is hard-coded in the application. | — |
| 7 | `sort_order` stored as text; `menu_level`, `icon_name`, `menu_name_arabic` unused. | — |

---

## 2. Design Decisions for the New Application

| Old ERP | New application |
|---------|-----------------|
| `parent_code` = parent's name | **`parent_id`** = parent's `id` (foreign key). Names can change freely and duplicates are harmless. |
| A row `parent_code = 'Root'` marks top level | **`parent_id IS NULL`** marks a root module. No fake "Root" row. |
| One copy of the menu per user/company/branch | **One** `menu_master` row per menu. Permissions live in separate tables. |
| `active` per user = permission | `menu_master.active` = the menu exists or is switched off for everyone. **Who can see it** comes from roles. |
| Only visible / hidden | `view`, `add`, `edit`, `delete`, `print`, `export`, `approve` per screen |
| Permission per user only | **Roles** (e.g. Accountant, Sales User, Storekeeper), plus optional **per-user overrides** |
| Modules hidden by setting `active = 'N'` for each user | **`company_menus`** switches a whole module (e.g. Payroll, Church, Education) off for a company |
| `icon = 'Y'` shortcuts | `is_shortcut` (default quick launch) plus **`user_menu_favorites`** (each user's own) |
| Screen link hard-coded | `route_name` (Laravel named route) or `url` |
| Groups shown even when empty | A module or sub menu is shown **only if the user can open at least one screen under it** |

---

## 3. New Design at a Glance

```
                    menu_master  (the tree: one row per menu)
                    ├── parent_id → menu_master.id      (NULL = root module)
                    │
     ┌──────────────┼──────────────────┬───────────────────────┬─────────────────────┐
     ▼              ▼                  ▼                       ▼                     ▼
role_menu_     user_menu_         company_menus          user_menu_            (routes in
permissions    permissions        (module on/off         favorites              Laravel use
(role × menu   (per-user          per company)           (user's own            menu_code)
 × actions)     overrides)                                shortcuts)
     ▲
     │
   roles ◄── user_roles ──► users   (optionally scoped to company / branch)
```

**Tree levels** (no fixed limit, normally 3):

| Level | `menu_type` | Example | Has route? | Gets permissions? |
|-------|-------------|---------|:----------:|:-----------------:|
| 1 | `module` | Master, Transaction, Reports, Admin | No | No (visible if any child is visible) |
| 2 | `group` | Sales, Purchase, Financial Reports | No | No (visible if any child is visible) |
| 3 | `screen` / `report` / `process` / `link` | Sales Invoice, Trial Balance, Year End Closing | Yes | **Yes** |

A level-2 item can also be a screen directly (e.g. *Master > Customer*). Groups
can be nested deeper if a module needs it.

---

## 4. `menu_master`: Columns to Add

Your current migration:

```php
Schema::create('menu_master', function (Blueprint $table) {
    $table->id();
    $table->string('menu_code', 50)->unique();
    $table->string('menu_name', 150);
    $table->string('menu_group', 100)->nullable();
    $table->string('route_name', 150)->nullable();
    $table->string('icon', 100)->nullable();
    $table->integer('sort_order')->default(0);
    $table->char('active', 1)->default('Y');
    $table->string('user_id', 30)->nullable();
    $table->dateTime('user_date')->nullable();
    $table->string('lastuser_id', 30)->nullable();
    $table->dateTime('lastuser_date')->nullable();
    $table->timestamps();
});
```

### 4.1 Additional columns

| Column | Type | Required? | Why |
|--------|------|:---------:|-----|
| **`parent_id`** | `foreignId` → `menu_master.id`, nullable | **Yes** | Builds the tree. `NULL` = root module. Replaces the old name-based `parent_code`. |
| **`menu_type`** | `varchar(20)`, default `'screen'` | **Yes** | `module`, `group`, `screen`, `report`, `process`, `link`, `separator`. Decides whether the item needs a route, gets permissions, or is just a container. |
| `menu_name_ar` | `varchar(150)`, nullable | Recommended | Arabic caption (the old `menu_name_arabic`) |
| `url` | `varchar(255)`, nullable | Recommended | External link or a legacy page that has no Laravel route |
| `permission_actions` | `varchar(100)`, nullable | Recommended | Actions that apply to this screen, e.g. `view,add,edit,delete,print` or `view,print,export` for a report. The permission screen only shows those check boxes. `NULL` = all actions. |
| `is_visible` | `char(1)`, default `'Y'` | Recommended | `N` = protected by a permission but **not shown** in the menu (e.g. an approval popup or edit page) |
| `is_shortcut` | `char(1)`, default `'N'` | Optional | Default quick-launch icon (the old `icon = 'Y'`) |
| `open_in_new_tab` | `char(1)`, default `'N'` | Optional | Open reports/prints in a new tab |
| `badge_key` | `varchar(50)`, nullable | Optional | Key of a counter to show next to the menu, e.g. `pending_invoices` (the approval counts this app already shows) |
| `description` | `varchar(255)`, nullable | Optional | Tooltip / help text |
| `legacy_menu_code` | `varchar(50)`, nullable | Optional | Old `user_menu.menu_code`, for migration and traceability |

### 4.2 Existing columns: how to use them

| Column | Use |
|--------|-----|
| `menu_code` | **Permanent unique key, also used as the permission key in routes and code.** Use readable, dotted codes that follow the tree: `transaction`, `transaction.sales`, `transaction.sales.invoice`. Never change a code once it's in use; rename `menu_name` instead. 50 characters is normally enough; use 100 if your codes get long. |
| `menu_name` | Caption. May repeat in different branches (e.g. two "Inventory" groups), because the link is `parent_id`. |
| `menu_group` | Optional **section heading inside a group** without adding another tree level. For example, the 43 items under *Sales* can be split into "Documents", "Approvals" and "Pending" columns in a mega-menu. Leave it NULL if you don't need it. |
| `route_name` | Laravel named route (`sales-invoices.index`). Required for `screen`, `report` and `process` unless `url` is set. |
| `icon` | Icon class, e.g. `fa-solid fa-file-invoice` |
| `sort_order` | Order among siblings (integer). Leave gaps (10, 20, 30) so items can be inserted later. |
| `active` | `N` switches the menu, **and everything under it**, off for all users |
| `user_id`, `user_date`, `lastuser_id`, `lastuser_date` | Audit only: who created / last changed the menu. They do **not** link a menu to a user any more; that's what the permission tables do. |

Not needed:
- `menu_level`: the depth is calculated when the tree is built.
- `path`: the whole table is small (a few hundred rows) and is loaded and cached
  in one query.

### 4.3 Migration: add the columns

If `menu_master` is already migrated, add a new migration. If it isn't deployed
yet, you can merge these lines into the original `create` migration instead.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_master', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('menu_master')->restrictOnDelete();
            $table->string('menu_type', 20)->default('screen');
            $table->string('menu_name_ar', 150)->nullable();
            $table->string('url', 255)->nullable();
            $table->string('permission_actions', 100)->nullable();
            $table->char('is_visible', 1)->default('Y');
            $table->char('is_shortcut', 1)->default('N');
            $table->char('open_in_new_tab', 1)->default('N');
            $table->string('badge_key', 50)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('legacy_menu_code', 50)->nullable();

            $table->index(['parent_id', 'sort_order']);
            $table->index('route_name');
        });

        DB::statement("ALTER TABLE menu_master
            ADD CONSTRAINT menu_master_type_chk
                CHECK (menu_type IN ('module','group','screen','report','process','link','separator')),
            ADD CONSTRAINT menu_master_flags_chk
                CHECK (active IN ('Y','N') AND is_visible IN ('Y','N')
                       AND is_shortcut IN ('Y','N') AND open_in_new_tab IN ('Y','N')),
            ADD CONSTRAINT menu_master_root_chk
                CHECK ((menu_type = 'module') = (parent_id IS NULL)),
            ADD CONSTRAINT menu_master_self_parent_chk
                CHECK (parent_id IS NULL OR parent_id <> id),
            ADD CONSTRAINT menu_master_target_chk
                CHECK (menu_type IN ('module','group','separator')
                       OR route_name IS NOT NULL OR url IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE menu_master
            DROP CONSTRAINT IF EXISTS menu_master_type_chk,
            DROP CONSTRAINT IF EXISTS menu_master_flags_chk,
            DROP CONSTRAINT IF EXISTS menu_master_root_chk,
            DROP CONSTRAINT IF EXISTS menu_master_self_parent_chk,
            DROP CONSTRAINT IF EXISTS menu_master_target_chk');

        Schema::table('menu_master', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropIndex(['route_name']);
            $table->dropColumn([
                'parent_id', 'menu_type', 'menu_name_ar', 'url', 'permission_actions',
                'is_visible', 'is_shortcut', 'open_in_new_tab', 'badge_key',
                'description', 'legacy_menu_code',
            ]);
        });
    }
};
```

The `menu_master_root_chk` constraint enforces two rules: every root is a
`module`, and every `module` is a root.

---

## 5. Related Tables

The flag columns in these tables use `boolean`, not `char(1)`. A permission grid
has many flags, PostgreSQL stores booleans efficiently, and Eloquent casts them
straight to `true` / `false`. `menu_master` keeps your existing `Y`/`N` style.

The examples assume Laravel's `users` table (`users.id`) and the `companies` /
`branches` tables from `README_CHART_OF_ACCOUNTS.md` section 12. If your users
are keyed by a code such as `JERO`, change `foreignId('user_id')` to
`string('user_id', 30)` with a foreign key to that column.

> If you already use `spatie/laravel-permission`, you can store
> `"{menu_code}.{action}"` (e.g. `transaction.sales.invoice.add`) as its
> permission names instead of `role_menu_permissions`. The tables below give
> you the old ERP's check-box grid (menu × action) directly and need no package.

### 5.1 `roles`

```php
Schema::create('roles', function (Blueprint $table) {
    $table->id();
    $table->string('role_code', 50)->unique();          // ACCOUNTANT, SALES_USER, ADMIN
    $table->string('role_name', 150);
    $table->string('description', 255)->nullable();
    $table->boolean('is_super_admin')->default(false);  // sees and can do everything
    $table->char('active', 1)->default('Y');
    $table->string('user_id', 30)->nullable();
    $table->dateTime('user_date')->nullable();
    $table->string('lastuser_id', 30)->nullable();
    $table->dateTime('lastuser_date')->nullable();
    $table->timestamps();
});
```

### 5.2 `role_menu_permissions`: what each role can do on each screen

```php
Schema::create('role_menu_permissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
    $table->foreignId('menu_id')->constrained('menu_master')->cascadeOnDelete();
    $table->boolean('can_view')->default(false);
    $table->boolean('can_add')->default(false);
    $table->boolean('can_edit')->default(false);
    $table->boolean('can_delete')->default(false);
    $table->boolean('can_print')->default(false);
    $table->boolean('can_export')->default(false);
    $table->boolean('can_approve')->default(false);
    $table->timestamps();

    $table->unique(['role_id', 'menu_id']);
});
```

Only add rows for `screen`, `report`, `process` and `link` items. Modules and
groups have no rows; they appear automatically when a child is visible.

### 5.3 `user_roles`: which roles a user has (optionally per company / branch)

```php
Schema::create('user_roles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
    $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete(); // NULL = all companies
    $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();   // NULL = all branches
    $table->timestamps();
});

DB::statement('ALTER TABLE user_roles ADD CONSTRAINT user_roles_unique
    UNIQUE NULLS NOT DISTINCT (user_id, role_id, company_id, branch_id)');
```

For example, user `JERO` can be *Accountant* in all branches and *Sales User*
only in branch 01.

### 5.4 `user_menu_permissions`: per-user exceptions (optional)

Use this when one user needs slightly more or less than their roles give, without
creating a new role. A `NULL` flag means "inherit from roles"; `true` / `false`
overrides.

```php
Schema::create('user_menu_permissions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('menu_id')->constrained('menu_master')->cascadeOnDelete();
    $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
    $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
    $table->boolean('can_view')->nullable();
    $table->boolean('can_add')->nullable();
    $table->boolean('can_edit')->nullable();
    $table->boolean('can_delete')->nullable();
    $table->boolean('can_print')->nullable();
    $table->boolean('can_export')->nullable();
    $table->boolean('can_approve')->nullable();
    $table->string('remark', 255)->nullable();          // why the exception was given
    $table->timestamps();
});

DB::statement('ALTER TABLE user_menu_permissions ADD CONSTRAINT user_menu_permissions_unique
    UNIQUE NULLS NOT DISTINCT (user_id, menu_id, company_id, branch_id)');
```

### 5.5 `company_menus`: switch modules on/off per company

No row means the menu is enabled. Add a row with `active = 'N'` to switch a menu,
and everything under it, off for that company. Typical examples are the old ERP
modules that were off for `JERO`: Payroll, Personnel, Church, Education,
Real Estate, Weighbridge.

```php
Schema::create('company_menus', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
    $table->foreignId('menu_id')->constrained('menu_master')->cascadeOnDelete();
    $table->char('active', 1)->default('N');
    $table->timestamps();

    $table->unique(['company_id', 'menu_id']);
});
```

### 5.6 `user_menu_favorites`: each user's own shortcuts

```php
Schema::create('user_menu_favorites', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('menu_id')->constrained('menu_master')->cascadeOnDelete();
    $table->integer('sort_order')->default(0);
    $table->timestamps();

    $table->unique(['user_id', 'menu_id']);
});
```

When a user has no favourites yet, show the menus with `is_shortcut = 'Y'`.

### 5.7 How a user's access is worked out

For user U in company C and branch B, a screen is **visible and usable** when
all of these are true:

1. The screen and **all its ancestors** have `active = 'Y'` in `menu_master`.
2. Neither the screen nor any ancestor is switched off in `company_menus` for C.
3. The action is listed in `permission_actions` (or that column is NULL).
4. The user has the right:
   - If any active role of U (for C/B, for C only, or global) has
     `is_super_admin = true`, the answer is **yes**.
   - Otherwise, start with **OR** across U's active roles
     (`role_menu_permissions`).
   - Then apply `user_menu_permissions` rows from least to most specific
     (global → company → branch → company + branch). Every non-NULL flag
     replaces the value so far.

A module or group is shown when **at least one** screen under it is visible.

---

## 6. Example Menu Data

Same top-level modules as the old ERP, with a dotted `menu_code`:

| menu_code | menu_name | menu_type | parent | route_name | sort | permission_actions |
|-----------|-----------|-----------|--------|------------|-----:|--------------------|
| `master` | Master | module | — | | 10 | |
| `master.accounts` | Chart of Accounts | screen | `master` | `accounts.index` | 10 | view,add,edit,delete,print,export |
| `master.customers` | Customer | screen | `master` | `customers.index` | 20 | view,add,edit,delete,print,export,approve |
| `master.suppliers` | Supplier | screen | `master` | `suppliers.index` | 30 | view,add,edit,delete,print,export,approve |
| `master.products` | Product | screen | `master` | `products.index` | 40 | view,add,edit,delete,print,export |
| `master.inventory` | Inventory | group | `master` | | 50 | |
| `master.inventory.units` | Units | screen | `master.inventory` | `units.index` | 10 | view,add,edit,delete |
| `transaction` | Transaction | module | — | | 20 | |
| `transaction.sales` | Sales | group | `transaction` | | 10 | |
| `transaction.sales.invoice` | Sales Invoice | screen | `transaction.sales` | `sales-invoices.index` | 10 | view,add,edit,delete,print,approve |
| `transaction.sales.return` | Sales Return | screen | `transaction.sales` | `sales-returns.index` | 20 | view,add,edit,delete,print,approve |
| `transaction.inventory` | Inventory | group | `transaction` | | 20 | |
| `transaction.inventory.delivery-order` | Delivery Order | screen | `transaction.inventory` | `delivery-orders.index` | 10 | view,add,edit,delete,print |
| `transaction.receipt` | Receipt | group | `transaction` | | 30 | |
| `transaction.receipt.voucher` | Receipt Voucher | screen | `transaction.receipt` | `receipts.index` | 10 | view,add,edit,delete,print,approve |
| `transaction.journal` | Journal | group | `transaction` | | 40 | |
| `transaction.journal.voucher` | Journal Voucher | screen | `transaction.journal` | `journals.index` | 10 | view,add,print,approve |
| `reports` | Reports | module | — | | 30 | |
| `reports.financial` | Financial Reports | group | `reports` | | 10 | |
| `reports.financial.ledger-single` | Ledger Report – Single | report | `reports.financial` | `reports.ledger-single` | 10 | view,print,export |
| `reports.financial.ledger-group` | Ledger Report – Group | report | `reports.financial` | `reports.ledger-group` | 20 | view,print,export |
| `reports.financial.trial-balance` | Trial Balance | report | `reports.financial` | `reports.trial-balance` | 30 | view,print,export |
| `reports.financial.ageing` | Ageing Report | report | `reports.financial` | `reports.ageing` | 40 | view,print,export |
| `admin` | Admin | module | — | | 40 | |
| `admin.menus` | Menu Setup | screen | `admin` | `menus.index` | 10 | view,add,edit,delete |
| `admin.roles` | Roles & Permissions | screen | `admin` | `roles.index` | 20 | view,add,edit,delete |
| `admin.users` | User Setup | screen | `admin` | `users.index` | 30 | view,add,edit,delete |
| `admin.monthly-closing` | Monthly Closing | process | `admin` | `closing.monthly` | 40 | view,approve |
| `admin.year-end-closing` | Year End Closing | process | `admin` | `closing.year-end` | 50 | view,approve |
| `utility` | Utility | module | — | | 50 | |

The two "Inventory" groups are no problem: they have different `menu_code` and
`parent_id`.

---

## 7. Laravel Implementation

### 7.1 Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuMaster extends Model
{
    protected $table = 'menu_master';

    public const CONTAINER_TYPES = ['module', 'group'];
    public const ACTION_TYPES    = ['screen', 'report', 'process', 'link'];
    public const ACTIONS         = ['view', 'add', 'edit', 'delete', 'print', 'export', 'approve'];

    protected $fillable = [
        'parent_id', 'menu_code', 'menu_name', 'menu_name_ar', 'menu_type', 'menu_group',
        'route_name', 'url', 'icon', 'sort_order', 'permission_actions', 'active',
        'is_visible', 'is_shortcut', 'open_in_new_tab', 'badge_key', 'description',
        'legacy_menu_code', 'user_id', 'user_date', 'lastuser_id', 'lastuser_date',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('menu_name');
    }

    /** @return list<string> */
    public function allowedActions(): array
    {
        return $this->permission_actions
            ? array_map('trim', explode(',', $this->permission_actions))
            : self::ACTIONS;
    }
}
```

Create `Role`, `RoleMenuPermission`, `UserRole`, `UserMenuPermission`,
`CompanyMenu` and `UserMenuFavorite` models in the same way. Cast the `can_*`
columns to `boolean`.

### 7.2 `MenuAccessService`: one place that decides access and builds the menu

```php
<?php

namespace App\Services;

use App\Models\CompanyMenu;
use App\Models\MenuMaster;
use App\Models\Role;
use App\Models\RoleMenuPermission;
use App\Models\User;
use App\Models\UserMenuPermission;
use App\Models\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class MenuAccessService
{
    /**
     * Screens the user may open, keyed by menu_code.
     *
     * @return array<string, array<string, bool>>  e.g. ['transaction.sales.invoice' => ['view' => true, 'add' => true, ...]]
     */
    public function effective(User $user, int $companyId, ?int $branchId): array
    {
        $key = sprintf('menu-access:%d:%d:%s:v%d', $user->id, $companyId, $branchId ?? 'all', $this->version());

        return Cache::remember($key, now()->addHours(12), function () use ($user, $companyId, $branchId) {
            $roleIds = UserRole::where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->pluck('role_id');

            $roles   = Role::whereIn('id', $roleIds)->where('active', 'Y')->get();
            $isSuper = $roles->contains('is_super_admin', true);

            $fromRoles = RoleMenuPermission::whereIn('role_id', $roles->pluck('id'))->get()->groupBy('menu_id');

            $overrides = UserMenuPermission::where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->get()
                ->sortBy(fn ($o) => ($o->company_id ? 1 : 0) + ($o->branch_id ? 2 : 0))
                ->groupBy('menu_id');

            $result = [];
            foreach ($this->reachableMenus($companyId) as $menu) {
                if (! in_array($menu->menu_type, MenuMaster::ACTION_TYPES, true)) {
                    continue;
                }

                $allowed = $menu->allowedActions();
                $flags   = [];
                foreach (MenuMaster::ACTIONS as $action) {
                    $column = "can_{$action}";
                    $value  = $isSuper || ($fromRoles->get($menu->id)?->contains($column, true) ?? false);

                    if (! $isSuper) {
                        foreach ($overrides->get($menu->id, []) as $override) {
                            if ($override->{$column} !== null) {
                                $value = (bool) $override->{$column};
                            }
                        }
                    }

                    $flags[$action] = $value && ($action === 'view' || in_array($action, $allowed, true));
                }

                if ($flags['view']) {
                    $result[$menu->menu_code] = $flags;
                }
            }

            return $result;
        });
    }

    public function can(User $user, int $companyId, ?int $branchId, string $menuCode, string $action = 'view'): bool
    {
        return $this->effective($user, $companyId, $branchId)[$menuCode][$action] ?? false;
    }

    /**
     * Nested menu for the sidebar / top bar. Empty modules and groups are removed.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(User $user, int $companyId, ?int $branchId): array
    {
        $access = $this->effective($user, $companyId, $branchId);

        $byParent = $this->reachableMenus($companyId)
            ->where('is_visible', 'Y')
            ->sortBy([['sort_order', 'asc'], ['menu_name', 'asc']])
            ->groupBy(fn ($m) => $m->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent, $access): array {
            $items = [];
            foreach ($byParent->get($parentId, collect()) as $menu) {
                if (in_array($menu->menu_type, MenuMaster::CONTAINER_TYPES, true)) {
                    $children = $build($menu->id);
                    if ($children !== []) {
                        $items[] = $this->node($menu, $children);
                    }
                } elseif ($menu->menu_type === 'separator' || isset($access[$menu->menu_code])) {
                    $items[] = $this->node($menu);
                }
            }
            return $items;
        };

        return $build(0);
    }

    /** Call after any change to menus, roles, permissions or company modules. */
    public function flush(): void
    {
        Cache::forever('menu_version', $this->version() + 1);
    }

    /** Active menus whose whole parent chain is active and not switched off for the company. */
    private function reachableMenus(int $companyId): Collection
    {
        $disabled = CompanyMenu::where('company_id', $companyId)->where('active', 'N')
            ->pluck('menu_id')->flip();
        $all = MenuMaster::where('active', 'Y')->get()->keyBy('id');

        return $all->filter(function (MenuMaster $menu) use ($all, $disabled) {
            for ($m = $menu, $depth = 0; $m !== null && $depth < 10; $depth++) {
                if ($disabled->has($m->id)) {
                    return false;
                }
                if ($m->parent_id === null) {
                    return true;
                }
                $m = $all->get($m->parent_id);   // null when the parent is inactive
            }
            return false;
        });
    }

    private function node(MenuMaster $menu, array $children = []): array
    {
        return [
            'code'        => $menu->menu_code,
            'name'        => app()->getLocale() === 'ar' && $menu->menu_name_ar ? $menu->menu_name_ar : $menu->menu_name,
            'type'        => $menu->menu_type,
            'group'       => $menu->menu_group,
            'icon'        => $menu->icon,
            'url'         => $menu->route_name ? route($menu->route_name) : $menu->url,
            'new_tab'     => $menu->open_in_new_tab === 'Y',
            'badge_key'   => $menu->badge_key,
            'children'    => $children,
        ];
    }

    private function version(): int
    {
        return (int) Cache::get('menu_version', 1);
    }
}
```

### 7.3 Protecting routes and buttons

**Middleware.** The URL is protected even if the user types it directly.

```php
<?php

namespace App\Http\Middleware;

use App\Services\MenuAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMenuAccess
{
    public function __construct(private MenuAccessService $menus) {}

    public function handle(Request $request, Closure $next, string $menuCode, string $action = 'view'): Response
    {
        abort_unless(
            $this->menus->can($request->user(), (int) session('company_id'), session('branch_id'), $menuCode, $action),
            403
        );

        return $next($request);
    }
}
```

Register it in `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias(['menu' => \App\Http\Middleware\EnsureMenuAccess::class]);
})
```

Use it in `routes/web.php`:

```php
Route::middleware('menu:transaction.sales.invoice,view')->group(function () {
    Route::get('/sales-invoices', [SalesInvoiceController::class, 'index'])->name('sales-invoices.index');
    Route::get('/sales-invoices/{invoice}', [SalesInvoiceController::class, 'show'])->name('sales-invoices.show');
});
Route::post('/sales-invoices', [SalesInvoiceController::class, 'store'])
    ->middleware('menu:transaction.sales.invoice,add');
Route::post('/sales-invoices/{invoice}/approve', [SalesInvoiceController::class, 'approve'])
    ->middleware('menu:transaction.sales.invoice,approve');
```

**Gate.** Use this for showing or hiding buttons in Blade. Register it in
`AppServiceProvider::boot()`:

```php
Gate::define('menu', fn (User $user, string $menuCode, string $action = 'view') =>
    app(MenuAccessService::class)->can($user, (int) session('company_id'), session('branch_id'), $menuCode, $action));
```

```blade
@can('menu', ['transaction.sales.invoice', 'add'])
    <a href="{{ route('sales-invoices.create') }}" class="btn btn-primary">New Invoice</a>
@endcan
```

### 7.4 Rendering the menu (Blade, recursive)

```blade
{{-- resources/views/components/menu-items.blade.php --}}
@props(['items'])
<ul>
    @foreach ($items as $item)
        @if ($item['type'] === 'separator')
            <li class="menu-separator"></li>
        @elseif ($item['children'])
            <li class="menu-{{ $item['type'] }}">
                <span><i class="{{ $item['icon'] }}"></i> {{ $item['name'] }}</span>
                <x-menu-items :items="$item['children']" />
            </li>
        @else
            <li>
                <a href="{{ $item['url'] }}" @if ($item['new_tab']) target="_blank" @endif>
                    <i class="{{ $item['icon'] }}"></i> {{ $item['name'] }}
                </a>
            </li>
        @endif
    @endforeach
</ul>
```

```blade
{{-- In the layout --}}
<x-menu-items :items="app(\App\Services\MenuAccessService::class)->tree(auth()->user(), session('company_id'), session('branch_id'))" />
```

For a top bar with dropdowns, render level 1 as the bar items and level 2 as the
dropdown columns. Inside a column, group level-3 items by `menu_group` for
section headings.

### 7.5 Admin screens to build

| Screen | What it does |
|--------|--------------|
| **Menu Setup** (`admin.menus`) | Tree editor: add, edit, move (change parent), re-order, activate/deactivate. Call `MenuAccessService::flush()` after every save. |
| **Roles & Permissions** (`admin.roles`) | Pick a role, then a grid of the menu tree (rows) × actions (check boxes). Only the actions in `permission_actions` are shown. Ticking a module or group ticks every screen under it. |
| **User Setup** (`admin.users`) | Assign roles per company/branch; optional per-user exceptions (`user_menu_permissions`) |
| **Company Modules** | Switch modules on/off per company (`company_menus`) |

Validation for **Menu Setup** (Form Request):
- `menu_code` is unique, lowercase, and uses only letters, digits, `.` and `-`.
- `parent_id` can't be the menu itself or one of its descendants. Walk up the
  chosen parent's chain and make sure the menu's own id isn't in it.
- `route_name` must exist: `Route::has($value)`.
- `module` ⇔ no parent. A `group` or `module` has no route.
- A menu that still has active children can't be deleted. Deactivate it
  instead. Deleting a menu also deletes its permission rows (cascade), so keep
  that action for menus that were never used.

---

## 8. Seeding the Menus

Keep the tree in one nested array so it's easy to read and review.

```php
<?php

namespace Database\Seeders;

use App\Models\MenuMaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuMasterSeeder extends Seeder
{
    public function run(): void
    {
        // [menu_code, menu_name, menu_type, route_name, icon, permission_actions, children]
        $tree = [
            ['master', 'Master', 'module', null, 'fa-solid fa-database', null, [
                ['master.accounts',  'Chart of Accounts', 'screen', 'accounts.index',  'fa-solid fa-sitemap', 'view,add,edit,delete,print,export', []],
                ['master.customers', 'Customer',          'screen', 'customers.index', 'fa-solid fa-user',    'view,add,edit,delete,print,export,approve', []],
                ['master.suppliers', 'Supplier',          'screen', 'suppliers.index', 'fa-solid fa-truck',   'view,add,edit,delete,print,export,approve', []],
            ]],
            ['transaction', 'Transaction', 'module', null, 'fa-solid fa-right-left', null, [
                ['transaction.sales', 'Sales', 'group', null, 'fa-solid fa-cart-shopping', null, [
                    ['transaction.sales.invoice', 'Sales Invoice', 'screen', 'sales-invoices.index', null, 'view,add,edit,delete,print,approve', []],
                    ['transaction.sales.return',  'Sales Return',  'screen', 'sales-returns.index',  null, 'view,add,edit,delete,print,approve', []],
                ]],
            ]],
            ['reports', 'Reports', 'module', null, 'fa-solid fa-chart-column', null, [
                ['reports.financial', 'Financial Reports', 'group', null, null, null, [
                    ['reports.financial.ledger-group',  'Ledger Report – Group', 'report', 'reports.ledger-group',  null, 'view,print,export', []],
                    ['reports.financial.trial-balance', 'Trial Balance',         'report', 'reports.trial-balance', null, 'view,print,export', []],
                ]],
            ]],
            ['admin', 'Admin', 'module', null, 'fa-solid fa-gear', null, [
                ['admin.menus', 'Menu Setup',          'screen', 'menus.index', null, 'view,add,edit,delete', []],
                ['admin.roles', 'Roles & Permissions', 'screen', 'roles.index', null, 'view,add,edit,delete', []],
            ]],
        ];

        DB::transaction(fn () => $this->insert($tree, null));
    }

    private function insert(array $items, ?int $parentId): void
    {
        foreach ($items as $i => [$code, $name, $type, $route, $icon, $actions, $children]) {
            $menu = MenuMaster::updateOrCreate(['menu_code' => $code], [
                'parent_id'          => $parentId,
                'menu_name'          => $name,
                'menu_type'          => $type,
                'route_name'         => $route,
                'icon'               => $icon,
                'permission_actions' => $actions,
                'sort_order'         => ($i + 1) * 10,
                'active'             => 'Y',
                'user_id'            => 'SYSTEM',
                'user_date'          => now(),
            ]);

            $this->insert($children, $menu->id);
        }
    }
}
```

`updateOrCreate` on `menu_code` means you can re-run the seeder after adding
new screens without creating duplicates. Also seed one `ADMIN` role with
`is_super_admin = true` and assign it to the first user.

The routes in the seeder must exist before you open the menu, because
`route()` throws an error for unknown names. Comment out menu entries until
their screens are built, or set them to `active = 'N'`.

---

## 9. Migrating from the Old `user_menu`

| Old column | New column | Notes |
|------------|------------|-------|
| `menu_code` | `legacy_menu_code` | Give each menu a new dotted `menu_code` from a mapping sheet. |
| `menu_name` | `menu_name` | Trim. Fix typos (e.g. "Job Asignment", "Goods Delivered-Note Invoiced"). |
| `menu_name_arabic` | `menu_name_ar` | |
| `parent_code` (= parent **name**) | `parent_id` | Resolve by name. For the 5 duplicated names, decide by the old `menu_code` prefix (`m_ma-` / `m_mst_` → Master, `m_txn_` → Transaction). Map `'Report'` to the `Reports` root. |
| `parent_code = 'Root'` | `parent_id = NULL`, `menu_type = 'module'` | |
| — | `menu_type` | Has children → `group`; under a report group → `report`; closing/backup/cost update → `process`; otherwise `screen` |
| `sort_order` (text) | `sort_order` (integer) | `CAST`; multiply by 10 to leave gaps |
| `icon_name` | `icon` | Empty in `infosblank` |
| `icon = 'Y'` | `is_shortcut = 'Y'` | |
| `active` (per user) | `role_menu_permissions.can_view` | Not `menu_master.active`. Group users with the same pattern of `active = 'Y'` screens into a role. For a menu that is `N` for **every** user, set `menu_master.active = 'N'` or switch it off in `company_menus`. |
| `company_code`, `branch_code`, `user_id` | `user_roles.company_id` / `branch_id` / `user_id` | |
| `menu_level` | — | Unused |

Steps:
1. Load all distinct menus (one user's rows are enough for the structure)
   into `menu_master`, two passes: insert all rows, then set `parent_id`.
2. For each old user, compare their `active = 'Y'` set with the roles you
   defined. Assign the closest role and put the differences in
   `user_menu_permissions`.
3. In the old ERP every visible screen allowed every action. Start each role
   with `can_view` plus the actions the role really needs; don't simply tick
   everything.
4. Print the new tree for each role and have the department heads sign it off.

---

## 10. Checklist

- [ ] `menu_master` has `parent_id` and `menu_type`; every root is a `module` and every module is a root.
- [ ] No menu is its own ancestor (checked in the Menu Setup form).
- [ ] Every `screen` / `report` / `process` has a valid `route_name` (`Route::has`) or a `url`.
- [ ] `menu_code` values are unique, permanent, and used in route middleware (`menu:<code>,<action>`).
- [ ] Every route that changes data has a middleware with the right action (`add`, `edit`, `delete`, `approve`), not just `view`.
- [ ] Modules and groups have no permission rows and disappear when they have no visible screens.
- [ ] Deactivating a module hides all its children; switching it off in `company_menus` hides it for that company only.
- [ ] `MenuAccessService::flush()` is called after every change to menus, roles, user roles, overrides or company modules.
- [ ] A super-admin role exists, so you can't lock yourself out.
- [ ] Feature tests cover: menu hidden without `can_view`; 403 on a direct URL; override `false` beats a role's `true`; branch-specific role only applies in that branch; disabled company module hides its screens.
