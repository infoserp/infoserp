# Chart of Accounts (`ac_master`) — How It Works

This document explains the Chart of Accounts (COA) used by the ERP in the
`infosblank` SQL Server database (`DESKTOP-GP23B1O\MSSQL2024`), so it can be
re-implemented in another application.

The data and structure described here were checked directly against the live
`infosblank` database: the table schema, all 66 rows, keys, foreign keys, the
`view_account_ledger` view, and the `parent_control` / `control_tb` /
`control_table` setup tables. Sections 10–12 are design recommendations for the
new application, not existing behaviour.

### Start here: how to read this document

| If you want to… | Read |
|-----------------|------|
| Understand how the **old ERP** (`infosblank`, SQL Server) chart of accounts works | Sections 1–9 |
| See a one-to-one port of the old design (**not recommended** for the new ERP) | Section 10 |
| Understand **why** the new design differs, from an accountant's point of view | Section 11 |
| **Build the new application (PostgreSQL + PHP Laravel)** | **Section 12. Start implementation here.** |
| Migrate data from `infosblank` into the new application | Sections 11.8 and 12.10 |

---

## 1. The Big Picture

The COA is a **tree** stored in a single table, `ac_master`.

- Every node in the tree (group *or* ledger) is one row.
- Each row has a short, unique code: **`ac_code`**.
- Each row also stores its **full path from the root**: **`account_code`**.
- Each row stores its **parent's full path**: **`parent_account_code`**.
- **`child_exist`** says whether the row is a **group** (`Y`, has children,
  cannot be posted to) or a **ledger** (`N`, leaf, transactions are posted here).

```
ac_code = "00050"                          ← the node's own ID (a "ledger code")
account_code = "0-1-00010-00039-00050"     ← path: root → Assets → Current Assets → Cash Account → Cash Main
parent_account_code = "0-1-00010-00039"    ← path of the parent (Cash Account)
```

So the **connections are made through `account_code` / `parent_account_code`
(the path strings), not through `ac_code`**. `ac_code` itself carries no
hierarchy information — it is just a sequential number.

---

## 2. The Five Root-Level Codes: `0`, `1`, `2`, `3`, `4`

| ac_code | account_code | parent_account_code | description | account_type | Statement (`control_tb.tb_type`) |
|---------|--------------|---------------------|-------------|--------------|----------------------------------|
| `0`     | `0`          | `0` (itself)        | *(blank)*   | *(blank)*    | — (virtual root)                 |
| `1`     | `0-1`        | `0`                 | ASSETS      | `A`          | `BS` (Balance Sheet)             |
| `2`     | `0-2`        | `0`                 | LIABILITY   | `L`          | `BS` (Balance Sheet)             |
| `3`     | `0-3`        | `0`                 | INCOME      | `I`          | `PL` (Profit & Loss)             |
| `4`     | `0-4`        | `0`                 | EXPENSE     | `E`          | `PL` (Profit & Loss)             |

Key points:

- **`0` is a technical root**, not a real account. Its description and type are
  blank, and its `parent_account_code` points to itself (`0`). It exists only
  so every path starts with `0-`.
- **`1`–`4` are the four main heads.** They are the only one-character
  `ac_code` values. Every other code is a 5-digit, zero-padded number
  (`00010`, `00150`…).
- Equity/Capital does **not** have its own root. It sits under LIABILITY
  (`0-2-00016 CAPITAL ACCOUNT`).
- `control_tb` maps the 4 heads to the financial statement they belong to
  (`BS` or `PL`). This is what the Trial Balance / Balance Sheet / P&L reports use
  to split accounts.

---

## 3. The Full Tree (as stored in `infosblank`)

`[G]` = group (`child_exist = 'Y'`), `[L]` = ledger (`child_exist = 'N'`).
Format: `ac_code  DESCRIPTION  (account_type)`.

```
0  (root)                                                    [G]
├── 1  ASSETS (A)                                            [G]   0-1
│   ├── 00010  CURRENT ASSETS (A)                            [G]   0-1-00010
│   │   ├── 00039  CASH ACCOUNT (A)                          [G]   0-1-00010-00039
│   │   │   └── 00050  CASH MAIN (A)                         [L]   0-1-00010-00039-00050
│   │   ├── 00040  BANK ACCOUNT (A)                          [G]   0-1-00010-00040
│   │   │   └── 00051  BANK A/C. (A)                         [L]
│   │   ├── 00041  DEPOSITS (A)                              [L]
│   │   ├── 00042  ACCOUNTS RECEIVABLE (A)                   [G]
│   │   │   ├── 00070  SUNDRY DEBTORS (A)                    [L]   ← default parent for customers
│   │   │   ├── 00071  STAFF DEBTORS (A)                     [L]
│   │   │   └── 00072  OTHER DEBTORS (A)                     [L]
│   │   ├── 00043  OTHER RECEIVABLE (A)                      [G]
│   │   │   └── 00075  PDC RECEIVED (A)                      [L]
│   │   ├── 00044  STAFF ADVANCE ACCOUNT (A)                 [L]
│   │   └── 00045  INVENTORY (A)                             [G]
│   │       ├── 00060  INVENTORY-OPENING                     [L]
│   │       ├── 00061  INVENTORY-PURCHASE                    [L]
│   │       ├── 00062  INVENTORY-PURCHASE RETURN             [L]
│   │       ├── 00063  INVENTORY-SALES                       [L]
│   │       ├── 00064  INVENTORY-SALES RETURN                [L]
│   │       ├── 00065  INVENTORY-STOCK ADJUSTMENT            [L]
│   │       ├── 00066  INVENTORY-STOCK TRANSFER OUT          [L]
│   │       └── 00067  INVENTORY-STOCK TRANSFER IN           [L]
│   ├── 00011  FIXED ASSETS (A)                              [G]
│   │   ├── 00150  Furniture & Fixtures                      [L]
│   │   ├── 00151  Buildings & Holdings                      [L]
│   │   ├── 00152  Vehicles & Carriers                       [L]
│   │   └── 00153  Office Equipment                          [L]
│   └── 00012  OTHER ASSETS (A)                              [G]
│       ├── 00155  Pre Payments                              [L]
│       └── 00156  Refundable Deposits                       [L]
├── 2  LIABILITY (L)                                         [G]   0-2
│   ├── 00015  CURRENT LIABILITY (L)                         [G]
│   │   ├── 00080  ACCOUNTS PAYABLE (L)                      [G]
│   │   │   ├── 00090  SUNDRY CREDITORS (L)                  [L]   ← default parent for suppliers
│   │   │   └── 00091  OTHER CREDITORS (L)                   [L]
│   │   ├── 00081  OTHER PAYABLE (L)                         [G]
│   │   │   └── 00085  PDC ISSUED (L)                        [L]   ⚠ see "Data issues"
│   │   └── 00092  UAE VAT (L)                               [G]
│   │       ├── 00093  INPUT VAT (L)                         [L]   ← VAT on purchases
│   │       └── 00094  OUTPUT VAT (L)                        [L]   ← VAT on sales
│   └── 00016  CAPITAL ACCOUNT (L)                           [G]
│       └── 00157  RETAINED EARNINGS CURRENT YEAR (NULL)     [L]   ← net P&L account
├── 3  INCOME (I)                                            [G]   0-3
│   ├── 00020  DIRECT INCOME (I)                             [G]   ← default parent for income
│   │   ├── 00130  CASH SALES                                [L]
│   │   ├── 00131  CREDIT SALES                              [L]
│   │   └── 00132  SALES RETURN                              [L]
│   └── 00021  INDIRECT INCOME (I)                           [G]
│       ├── 00133  SALES OF FIXED ASSETS                     [L]
│       └── 00134  STOCK IN HAND (P&L)                       [L]
└── 4  EXPENSE (E)                                           [G]   0-4
    ├── 00030  DIRECT EXPENSE (I ⚠)                          [G]
    │   ├── 00100  PURCHASE EXPENSE (E)                      [G]
    │   │   ├── 00140  CASH PURCHASE                         [L]
    │   │   ├── 00141  CREDIT PURCHASE                       [L]
    │   │   └── 00142  PURCHASE RETURN                       [L]
    │   └── 00101  COST OF STOCK (E)                         [G]
    │       ├── 00103  COST OF STOCK-PURCHASE                [L]
    │       ├── 00104  COST OF STOCK-PURCHASE RETURN         [L]
    │       ├── 00105  COST OF STOCK-SALES                   [L]
    │       ├── 00106  COST OF STOCK-SALES RETURN            [L]
    │       └── 00107  COST OF STOCK-STOCK ADJUSTMENT        [L]
    └── 00031  INDIRECT EXPENSE (I ⚠)                        [G]
        └── 00110  GENERAL&ADMIN EXPENSE                     [L]
```

Totals for the blank chart: **66 rows** (24 groups including the root, 42 ledgers),
one company (`01`), maximum depth **5 path segments**, longest `account_code` is
21 characters. The test customer `00158` and test supplier `00159` were added
later, making 68 rows (see section 7.1).

---

## 4. Core Columns Explained

### 4.1 Identity & hierarchy

| Column | Type | Meaning / Rules |
|--------|------|-----------------|
| `company_code` | `varchar(2)` NOT NULL | Company the account belongs to (`01`). **Part of the primary key.** FK → `company_master`. Every query must filter by it. |
| `ac_code` | `varchar(5)` NOT NULL | The node's own code (the "ledger code"). **Primary key = (`company_code`, `ac_code`).** Roots are `0`–`4`; everything else is 5-digit zero-padded. Unique per company, **does not encode the hierarchy**. This is the value stored in every transaction table. |
| `account_code` | `varchar(50)` | **Materialised path** from the root to this node: the `ac_code`s of all ancestors plus its own, joined with `-`. Example: `0-4-00030-00101-00105`. This is the column used for all tree logic (roll-ups, "is descendant of", sorting the tree). |
| `parent_account_code` | `varchar(50)` | The parent's `account_code`. Always equals `account_code` with the last `-segment` removed. For the root `0` it is `0` (self). Use it for "direct children of X". |
| `child_exist` | `char(1)` default `'N'` | `Y` = **group** (has children, used only for grouping/totals, **never posted to**). `N` = **ledger** (leaf, can be used in vouchers/invoices). |
| `u_key` | `float` | Numeric form of `ac_code` (`'00105'` → `105`). All 66 rows have `u_key = CAST(ac_code AS float)`. Used to generate the next code (`MAX(u_key) + 1`, then zero-pad to 5) and for numeric sorting. |
| `account_type` | `varchar(5)` | Accounting class: `A` = Asset, `L` = Liability (includes Capital/Equity), `I` = Income, `E` = Expense. Inherited from the root head the account lives under. Determines normal balance side and BS/PL placement. |
| `description` | `nvarchar(150)` | Account name (Unicode). |
| `active` | `char(1)` default `'Y'` | `Y` = usable. `N` = hidden from selection lists but history is kept. |

### 4.2 Other columns that matter for a COA

| Column | Default | Meaning |
|--------|---------|---------|
| `blocked`, `blocked_by`, `blocked_date`, `blocked_remark` | `'N'` | Account is temporarily frozen for posting (different from `active`: blocked accounts still show but can't be used). |
| `confidential` | `'N'` | Restricts the account (e.g. salaries) to privileged users in reports/lookups. |
| `costcenter_required` | `'N'` | If `Y`, every posting to this ledger must carry a cost center (`costcenter_code` on the transaction line). |
| `subledger_required` | `'N'` | If `Y`, postings must carry a sub-ledger (`ac_subcode`, master table `ac_subledger(company_code, ac_subcode, ac_code, ac_name)`). |
| `intercompany_account` | `'N'` | Marks inter-company accounts (paired in `intercompany_account`). |
| `stk_transfer_ac` | `'N'` | Marks accounts used for stock-transfer postings. |
| `currency_code` | NULL | Foreign-currency ledger (e.g. USD bank account). NULL = base currency. |
| `branch_code` | NULL | Optional branch ownership. Branch restriction also exists in `branch_account(branch_code, ac_code)`. |
| `description_arabic`, `short_name` | NULL | Second-language name and short label. |
| `debit_credit` | NULL | Intended "normal balance" flag (`D`/`C`). **Not filled in this DB.** Derive it from `account_type` instead. |
| `ac_level`, `parent_ac_code`, `prefix`, `sort_order` | NULL | Legacy / unused in this DB. Level is derived from the number of segments in `account_code`. |
| `opening_dr`, `opening_cr`, `current_dr`, `current_cr`, `budget` | NULL | Cached balances, not maintained. **Real balances are computed from transactions** (see section 6). |
| `user_id`, `user_date`, `last_userid`, `last_userdate` | | Audit trail (created by / modified by). |

The remaining ~180 columns (address, phone, credit limit, bank details, salesman,
VAT number `tax_no`, membership/parish fields, passport, etc.) exist because
**customers and suppliers are also ledgers in `ac_master`** (see section 7).
They are party/contact attributes and are **not needed for the COA tree itself**.

---

## 5. How the Hierarchy Works (the rules)

### 5.1 The path rule

```
account_code(child) = account_code(parent) + '-' + ac_code(child)
parent_account_code(child) = account_code(parent)
```

Examples from the data:

| ac_code | account_code | parent_account_code | Parent |
|---------|--------------|---------------------|--------|
| `1` | `0-1` | `0` | root |
| `00010` | `0-1-00010` | `0-1` | ASSETS |
| `00039` | `0-1-00010-00039` | `0-1-00010` | CURRENT ASSETS |
| `00050` | `0-1-00010-00039-00050` | `0-1-00010-00039` | CASH ACCOUNT |

### 5.2 Level / depth

```
level = number of '-' in account_code        (root '0' = 0, '0-1' = 1, ...)
```

### 5.3 Codes are global, not per-branch-of-the-tree

`ac_code` is a **single running sequence across the whole company**. Siblings do
not share a prefix: e.g. under CURRENT ASSETS you have `00039`, `00040`…`00045`,
while their children are `00050`, `00051`, `00060`… This is why you **must not**
derive the parent from `ac_code`. Always use `account_code`.

### 5.4 Groups vs. ledgers

- Only `child_exist = 'N'` rows appear in voucher/invoice account pickers.
  Every lookup in this app filters `ac_master.child_exist = 'N'`.
- When a new account is added under a node, that node's `child_exist`
  must become `Y`. A node may only go back to `N` if it has no children.
- A group must never have transactions posted against it.

### 5.5 Account type inheritance

`account_type` is copied from the head (`1`→`A`, `2`→`L`, `3`→`I`, `4`→`E`).
In a new system, compute it from the first real segment of `account_code`
(`0-<head>-...`) rather than trusting the stored value (see Data issues).

---

## 6. How Balances Work (posting and roll-up)

### 6.1 Transactions only reference leaf `ac_code`

All posting tables store **only `ac_code`** (with `company_code`). 27 tables have a
foreign key to `ac_master(company_code, ac_code)`, including:

`journal_voucher_d`, `chq_receipt_h/_d`, `chq_payment_h/_d`, `chq_gen_receipt_d`,
`chq_gen_payment_d`, `invoice_h/_d`, `sales_return_h/_d`, `sales_order_h`,
`grn_voucher_h/_d`, `goods_receipt_h`, `general_purchase_h/_d`,
`purchase_return_h/_d`, `service_invoice_h/_d`, `delivery_note_h`,
`credit_note_d`, `debit_note_d`, `auto_jv_d`, `opening_balance`, `year_closing_d`.

The view **`view_account_ledger`** UNIONs all of these into one general-ledger
stream with the columns:

```
companycode, branchcode, docno, docdate, doctype, accode, debit, credit, currency, exrate, narration
```

That view is the single source of truth for account balances. The cached balance
columns in `ac_master` are not used.

### 6.2 Group balance = sum of all descendant ledgers

Because `account_code` is a path, a group's balance is simply all ledgers whose
path starts with the group's path:

```sql
-- Balance of every group and ledger, rolled up through the path
WITH ledger_bal AS (
    SELECT l.companycode, l.accode,
           SUM(l.debit * ISNULL(l.exrate,1))  AS dr,
           SUM(l.credit * ISNULL(l.exrate,1)) AS cr
    FROM view_account_ledger l
    WHERE l.companycode = '01'
    GROUP BY l.companycode, l.accode
)
SELECT g.ac_code, g.account_code, g.description, g.child_exist,
       SUM(ISNULL(b.dr,0)) AS total_dr,
       SUM(ISNULL(b.cr,0)) AS total_cr
FROM ac_master g
LEFT JOIN ac_master a
       ON a.company_code = g.company_code
      AND (a.account_code = g.account_code               -- itself
           OR a.account_code LIKE g.account_code + '-%') -- or any descendant
LEFT JOIN ledger_bal b
       ON b.companycode = a.company_code AND b.accode = a.ac_code
WHERE g.company_code = '01'
GROUP BY g.ac_code, g.account_code, g.description, g.child_exist
ORDER BY g.account_code;
```

Always include the `'-%'` delimiter in the `LIKE`. Without it, `0-1` would also
match a hypothetical `0-10...` path.

### 6.3 Normal balance side

| account_type | Normal balance | Statement |
|--------------|----------------|-----------|
| `A` Asset | Debit (`Dr - Cr`) | Balance Sheet |
| `L` Liability / Capital | Credit (`Cr - Dr`) | Balance Sheet |
| `I` Income | Credit (`Cr - Dr`) | Profit & Loss |
| `E` Expense | Debit (`Dr - Cr`) | Profit & Loss |

### 6.4 Trial Balance

The ERP builds the Trial Balance in `temp_trial_balance`, which carries
`ac_code, account_code, parent_account_code, description, account_type, child_exist,
opening_dr/cr, current_dr/cr, balance_dr/cr, budget, pdc, sort_order`. It
computes ledger balances, rolls them up into the groups using `account_code`,
and then uses `control_tb.tb_type` (`BS` / `PL`) to split them into the Balance
Sheet and the P&L. The net profit is transferred to **`00157 RETAINED EARNINGS
CURRENT YEAR`** (`control_table.ac_net_pl = '00157'`).

---

## 7. Customers, Suppliers & Banks Are Ledgers Too (`parent_control`)

There is no separate customer or supplier master. A customer is just a ledger row
in `ac_master` created under a designated parent group. `parent_control` tells
the system which group to use:

| parent_type | Meaning | ac_code | Group |
|-------------|---------|---------|-------|
| `C` | Customers | `00070` | SUNDRY DEBTORS |
| `S` | Suppliers | `00090` | SUNDRY CREDITORS |
| `B` | Banks | `00040` | BANK ACCOUNT |
| `I` | Income (sales accounts) | `00020` | DIRECT INCOME |

(`parent_control` columns: `company_code, branch_code, parent_type, ac_code,
set_default, sort_order, account_code`. `set_default = 'Y'` marks the default
parent when several exist for the same type.)

### 7.1 What the ERP stores when a customer or supplier is created (verified)

A `TEST CUSTOMER` and a `TEST SUPPLIER` were created in the ERP on 2026-10-19,
and the stored rows were read back from `infosblank`:

| Column | TEST CUSTOMER | TEST SUPPLIER |
|--------|---------------|---------------|
| `ac_code` | `00158` | `00159` |
| `u_key` | `158` | `159` |
| `account_code` | `0-1-00010-00042-00070-00158` | `0-2-00015-00080-00090-00159` |
| `parent_account_code` | `0-1-00010-00042-00070` | `0-2-00015-00080-00090` |
| `parent_ac_code` | `00070` | `00090` |
| `account_type` | **NULL** | **NULL** |
| `child_exist` / `active` | `N` / `Y` | `N` / `Y` |
| `approved` / `verified` | `Y` / `N` | `Y` / `N` |
| `blocked`, `confidential`, `costcenter_required`, `subledger_required`, `intercompany_account`, `stk_transfer_ac`, `bank_transfer` | `N` | `N` |
| `user_id` / `user_date` | `JERO` / creation time | `JERO` / creation time |
| `service_date` | Same as `user_date` | Same as `user_date` |
| `last_userid` / `last_userdate` | Set about 40 seconds after creation | Set about 40 seconds after creation |

Party details filled from the entry form:

| Form field | Column(s) |
|------------|-----------|
| Name | `description` (saved without trimming; the customer name kept a trailing space) |
| Reference code | `ref_code` |
| TRN (VAT number) | `tax_no` |
| Credit limit | **`cr_amount`** (`cr_limit` stays NULL) |
| Credit days | `cr_days` |
| Salesman | `salesman_code` (customer only, e.g. `GEN`) |
| Location / emirate | `service_location_code` (customer only, e.g. `FUJ`) |
| Address | `address`, `street`, `landmark`, `city`, `country`, `po_box` |
| Phones / web | `tel_off`, `tel_res`, `fax_off`, `email`, `www` |
| Contact person | `contact_name`, `contact_designation`, `contact_no` |

**How the ERP actually creates a party:**

1. Takes the next code: `MAX(u_key) + 1`, zero-padded to 5 digits. Customers
   and suppliers share **the same sequence** as every GL account: `00158`,
   `00159`.
2. Finds the parent from `parent_control` (`C` → `00070`, `S` → `00090`) and
   sets:
   - `parent_account_code` = the parent's `account_code`
   - `account_code` = parent `account_code` + `-` + new `ac_code`
   - `parent_ac_code` = the parent's `ac_code`. This column is empty on all
     66 original rows and is only filled for parties.
3. Saves `child_exist = 'N'`, `active = 'Y'`, `approved = 'Y'`,
   `verified = 'N'`.
4. Leaves **`account_type` NULL**. Reports must take the type from the
   ancestor head (`0-1` → `A`, `0-2` → `L`) through the path.
5. Writes **only to `ac_master`**. No other table received a row for `00158`
   or `00159` (contacts, sites, sub-ledgers, branch accounts, etc. were all
   checked).
6. **Does not update the parent.** `00070 SUNDRY DEBTORS` and `00090 SUNDRY
   CREDITORS` still have `child_exist = 'N'`, even though they now have
   children.

**Consequences of point 6:**

- The rule "`child_exist = 'Y'` means it has children" (section 5.4) is
  **not reliable for `parent_control` groups**. In this ERP, `00070` and
  `00090` act as groups because `parent_control` says so, not because of
  `child_exist`.
- Pickers that only filter `child_exist = 'N'` (for example the receipt screen
  lookup) will list `SUNDRY DEBTORS` / `SUNDRY CREDITORS` as postable
  accounts alongside the real customers and suppliers. Users can therefore
  post to the parent by mistake.
- The path-based roll-up (section 6.2) still works, because it uses
  `account_code` and not `child_exist`.
- In the new application, **always set `parent.child_exist = 'Y'` (or
  `is_group = 1`) in the same transaction that creates the child.**

This is how the customer lookup in the sales invoice screen
(`modules/invoice/header/sales_invoice_h_01.php`) finds customers:

```sql
SELECT ...
FROM ac_master
WHERE ac_master.company_code = ?
  AND ac_master.child_exist = 'N'
  AND ac_master.parent_account_code IN (
        SELECT ac_master.account_code
        FROM ac_master
        INNER JOIN parent_control
                ON ac_master.company_code = parent_control.company_code
               AND ac_master.ac_code      = parent_control.ac_code
        WHERE parent_control.company_code = ? AND parent_control.parent_type = 'C')
```

The receipt screen (`modules/receipt/header/d_chq_receipt_h_01.php`) shows the
parent group name next to each ledger by self-joining on the path:

```sql
LEFT JOIN ac_master AS parent_group
       ON parent_group.company_code = ac_master.company_code
      AND parent_group.account_code = ac_master.parent_account_code
```

---

## 8. Default (System) Accounts — `control_table`

Automatic postings (VAT, cost of sales, stock, P&L closing) don't ask the user
for an account. They read fixed `ac_code`s from `control_table` (one row per
company + branch). Values currently configured:

| control_table column | ac_code | Account |
|----------------------|---------|---------|
| `ac_net_pl` | `00157` | RETAINED EARNINGS CURRENT YEAR |
| `tax1_sales_cr` | `00094` | OUTPUT VAT |
| `tax1_salesreturn_dr` | `00094` | OUTPUT VAT |
| `tax1_purchase_dr` | `00093` | INPUT VAT |
| `tax1_purchasereturn_cr` | `00093` | INPUT VAT |

Other mapping columns exist but are **NULL** in this DB. They are meant to point
at the INVENTORY (`0006x`) and COST OF STOCK (`0010x`) ledgers:

`cost_opening_dr/cr`, `cost_sales_dr/cr`, `cost_sales_return_dr/cr`,
`cost_purchase_dr/cr`, `cost_purchase_return_dr/cr`, `cost_adjust_dr/cr`,
`cost_grn_dr/cr`, `cost_stk_assembly_dr/cr`, `ac_stk_pl`, `ac_stk_bs`,
`ac_open_stk`, `ac_close_stk`, `ac_purchase`, `ac_cost_sales`, `duty_dr/cr`,
`tax1_creditnote_dr`, `tax1_debitnote_cr`, `tax1_payment_dr`, `tax1_receipt_cr`,
`tax2_*`, `tax3_*`.

Related flags: `search_order_account = 2` (account search sort order) and
`account_popup_enanbled = 'Y'` (show the account picker popup).

Item-level mappings also exist on `item_group` (`ac_code_sales`,
`ac_code_purchase`, `ac_code_cost`, `ac_code_stock`), and many masters (bank,
petty cash, expense, salesman, employee, etc.) carry an `ac_code` linking them
to their ledger.

---

## 9. Data Issues Found in `infosblank` (don't copy these)

| # | Row | Problem | Correct value |
|---|-----|---------|---------------|
| 1 | `00085 PDC ISSUED` | `account_code = 0-2-00015-00085` but `parent_account_code = 0-2-00015-00081`. The path and the parent disagree. | Either `account_code = 0-2-00015-00081-00085` (under OTHER PAYABLE) or `parent_account_code = 0-2-00015`. Following the parent column, it belongs under OTHER PAYABLE. |
| 2 | `00030 DIRECT EXPENSE`, `00031 INDIRECT EXPENSE` | `account_type = 'I'` even though they sit under EXPENSE (`0-4`). | `E` |
| 3 | `00157 RETAINED EARNINGS CURRENT YEAR` | `account_type = NULL`. | `L` (it sits under `0-2`) |
| 4 | Root `0` | `account_type = ' '` and `parent_account_code = '0'` (self-reference). | Intentional sentinel; in a new app use `parent = NULL`. |
| 5 | `debit_credit`, `ac_level` | NULL on all rows. | Derive from `account_type` and path depth. |
| 6 | Customers / suppliers (`00158`, `00159`) | Created with `account_type = NULL`. | Inherit from the head: `A` for customers, `L` for suppliers. |
| 7 | `00070 SUNDRY DEBTORS`, `00090 SUNDRY CREDITORS` | Still `child_exist = 'N'` after parties were created under them, so they appear in account pickers as postable ledgers. | `Y` as soon as the first child is created. |

Checks that **passed**: every `child_exist = 'Y'` row has at least one child,
no `child_exist = 'N'` row has children, and `u_key` equals the numeric
`ac_code` on every row.

---

## 10. Re-implementing in Another Application

### 10.1 Recommended table

> This table copies the current design with only small fixes, which makes a
> one-to-one port easy. For a new ERP, section 11 recommends going further: a
> `BIGINT` surrogate key, a separate party master, and control accounts.
> **For the new PostgreSQL + Laravel application, use the schema in section 12
> instead of this one.**

```sql
CREATE TABLE coa_account (
    company_code        VARCHAR(2)    NOT NULL,
    ac_code             VARCHAR(10)   NOT NULL,          -- own code (ledger code)
    account_code        VARCHAR(200)  NOT NULL,          -- materialised path: 0-1-00010-00039-00050
    parent_account_code VARCHAR(200)  NULL,              -- NULL only for the root
    description         NVARCHAR(150) NOT NULL,
    description_ar      NVARCHAR(150) NULL,
    account_type        CHAR(1)       NOT NULL,          -- A / L / I / E
    statement_type      CHAR(2)       NOT NULL,          -- BS / PL (from the head)
    level_no            TINYINT       NOT NULL,          -- count of '-' in account_code
    u_key               INT           NOT NULL,          -- numeric ac_code, for sequencing
    child_exist         CHAR(1)       NOT NULL DEFAULT 'N',  -- Y = group, N = ledger
    active              CHAR(1)       NOT NULL DEFAULT 'Y',
    blocked             CHAR(1)       NOT NULL DEFAULT 'N',
    confidential        CHAR(1)       NOT NULL DEFAULT 'N',
    costcenter_required CHAR(1)       NOT NULL DEFAULT 'N',
    subledger_required  CHAR(1)       NOT NULL DEFAULT 'N',
    currency_code       VARCHAR(3)    NULL,
    created_by          VARCHAR(15)   NULL,
    created_at          DATETIME      NULL,
    updated_by          VARCHAR(15)   NULL,
    updated_at          DATETIME      NULL,
    CONSTRAINT PK_coa_account PRIMARY KEY (company_code, ac_code),
    CONSTRAINT UQ_coa_account_path UNIQUE (company_code, account_code),
    CONSTRAINT CK_coa_type  CHECK (account_type IN ('A','L','I','E')),
    CONSTRAINT CK_coa_child CHECK (child_exist IN ('Y','N'))
);
CREATE INDEX IX_coa_parent ON coa_account (company_code, parent_account_code);
```

Keep party data (customer/supplier address, credit limit, VAT no.) in a
separate `party` table keyed by `(company_code, ac_code)` instead of 200 extra
columns on the COA table.

### 10.2 Seed data

Seed the 5 root rows (`0`, `1`, `2`, `3`, `4`) and then the groups and ledgers
from section 3, applying the fixes from section 9. Paths are built as
`parent.account_code + '-' + ac_code`.

### 10.3 Business rules to enforce

1. **Create account under parent P**
   - `ac_code` = `RIGHT('00000' + CAST(MAX(u_key)+1 AS varchar), 5)` for the company.
   - `parent_account_code` = `P.account_code`.
   - `account_code` = `P.account_code + '-' + ac_code`.
   - `account_type` / `statement_type` = P's values.
   - `level_no` = P.level_no + 1.
   - Set `P.child_exist = 'Y'`.
   - **Do this in one transaction.**
   - Block creating a child under P if P is a ledger that already has transactions.
     Otherwise its existing postings would end up on a group.
2. **Post a transaction**: allowed only if `child_exist = 'N' AND active = 'Y' AND blocked = 'N'`.
   Enforce `costcenter_required` / `subledger_required`.
3. **Delete account**: only if it has no children and no transactions. After
   deleting, if the parent has no remaining children, set `parent.child_exist = 'N'`.
4. **Move account to another parent**: rewrite `account_code` for the node
   **and all descendants** (replace the old path prefix with the new one),
   update its `parent_account_code`, recompute `level_no` and `account_type`, and
   fix `child_exist` on the old and new parents. Transactions don't change
   because they reference `ac_code`.
5. **Rename**: only `description` changes; codes and paths never change.
6. Never allow moving an account across heads (e.g. Asset → Expense) once it
   has postings, unless that reclassification is intended.

### 10.4 Useful queries

```sql
-- Direct children of a node
SELECT * FROM coa_account
WHERE company_code = @co AND parent_account_code = @parent_path
ORDER BY u_key;

-- All descendants (subtree) of a node
SELECT * FROM coa_account
WHERE company_code = @co AND account_code LIKE @path + '-%';

-- All ancestors (breadcrumb) of a node: every path that is a prefix of mine
SELECT a.* FROM coa_account a
JOIN coa_account me ON me.company_code = a.company_code AND me.ac_code = @ac
WHERE me.account_code = a.account_code
   OR me.account_code LIKE a.account_code + '-%'
ORDER BY a.level_no;

-- Postable ledgers for pickers
SELECT ac_code, description FROM coa_account
WHERE company_code = @co AND child_exist = 'N' AND active = 'Y' AND blocked = 'N'
ORDER BY ac_code;

-- Render the tree in order (indent by level_no)
SELECT REPLICATE('    ', level_no) + description AS tree, ac_code, child_exist
FROM coa_account WHERE company_code = @co
ORDER BY account_code;
```

`ORDER BY account_code` renders the tree in the correct order because every code
at the same depth has the same width (5 digits, roots 1 digit). If you change
the code width, zero-pad every new code to the same width so string sorting
keeps working.

### 10.5 Supporting tables to replicate

| Table | Purpose |
|-------|---------|
| `control_tb (company_code, ac_code, account_code, tb_type)` | Maps heads `1`–`4` to `BS` / `PL`. |
| `parent_control (company_code, branch_code, parent_type, ac_code, set_default)` | Default parent group for Customers `C`, Suppliers `S`, Banks `B`, Income `I`. |
| `control_table` (account columns only) | System accounts for automatic postings: VAT, cost of sales, stock, net P&L. |
| `ac_subledger (company_code, ac_subcode, ac_code, ac_name)` | Sub-ledgers for accounts with `subledger_required = 'Y'`. |
| `branch_account (branch_code, ac_code)` | Optional restriction of accounts to branches. |

---

## 11. Expert Review: Recommendations for the New Accounting ERP

This section is written from a chartered accountant / ERP architect point of view.
It answers three questions:

1. Should the new application follow the same flow as `ac_master`?
2. Is the 5-digit `ac_code` a problem?
3. What should change so the new ERP is ready for future needs?

> The customer/supplier flow was checked using a test customer (`00158`) and a
> test supplier (`00159`) created in the old ERP (see section 7.1). Those tests
> confirmed that every party uses up a code from the same 5-digit sequence as
> the GL accounts, which supports the recommendations below.

### 11.1 Short answer

**Keep the concept, change the implementation.**

| Keep from the current design | Change in the new design |
|------------------------------|--------------------------|
| A single tree of accounts (groups and ledgers) | Customers and suppliers created as individual GL ledgers inside the COA |
| Posting allowed only to leaf ledgers (`child_exist = 'N'`) | 5-digit `ac_code` limit and `float` `u_key` |
| Path-based roll-up of balances (`account_code`) | One running number for all accounts, so codes carry no meaning |
| BS/PL classification of the heads (`control_tb`) | Equity hidden under Liability; no separate Cost of Sales head |
| Default system accounts in settings (`control_table`, `parent_control`) | General ledger calculated on the fly by a large UNION view instead of a posted journal |
| `active` / `blocked` / `confidential` / cost-center / sub-ledger flags | `float` used for money; 221-column table mixing COA and party data |

### 11.2 The 5-digit `ac_code` limit

**Yes, it is a real drawback in this design.**

- `ac_code` is `varchar(5)` and is **one running sequence for every account in
  the company**: groups, ledgers, **and every customer and supplier**. The
  maximum is `99999` codes per company. That is not a lot. A trading or retail
  business that creates a ledger for every walk-in or B2C customer can exhaust
  it in a few years. There is no recycling either, because deleted or inactive
  codes stay in history.
- `u_key` is `float`. It works for small integers, but it is the wrong type for
  a sequence. Use an integer type.
- `ac_code` is the primary key **and** the foreign key in 27+ transaction tables.
  Widening it later means altering every one of those tables and their
  constraints. That is an expensive migration.
- `account_code` (the path) is `varchar(50)`. With 5-digit codes it fits about 8
  levels. Wider codes or deeper trees will overflow it.

**What to do in the new ERP:**

1. **Separate the internal key from the displayed number.**
   - `account_id BIGINT IDENTITY` is the primary key and the only value stored
     in transaction tables. It never changes and has no practical limit.
   - `account_no VARCHAR(20)` is the number users see. It is unique per company
     and can be edited or re-numbered without touching any transaction.
2. Store the hierarchy as `parent_id` plus a path of IDs
   (`path VARCHAR(900)`, e.g. `/1/5/23/`) or a closure table. Store `level_no`
   as a column too. Don't sort the tree by string-comparing variable-width codes.
   Sort by `account_no`, or by a fixed-width sort path.
3. Use **meaningful, hierarchical account numbers** (section 11.6). Because
   customers and suppliers move out of the COA (section 11.3), the COA stays at
   a few hundred accounts. 6 to 8 digits is then plenty for decades.
4. Give customers and suppliers their own code series in the party table, for
   example `C000001` and `S000001` as `VARCHAR(20)`.

**Minimum change if you must keep the current style:** use `ac_code VARCHAR(12)`,
`u_key BIGINT`, and `account_code VARCHAR(500)`, and pad every code to the same
width so `ORDER BY account_code` still works.

### 11.3 Customers and suppliers: use control accounts and a sub-ledger

**Current behaviour:** every customer is a new ledger under `00070 SUNDRY DEBTORS`.
Every supplier is a new ledger under `00090 SUNDRY CREDITORS`. All their details
(address, TRN, credit limit, bank) are stored in the same `ac_master` row. This
is the "Tally-style" approach. It is simple, but it does not scale:

- The COA and the trial balance grow to thousands of lines, which makes them
  unreadable for management and auditors.
- It uses up the 5-digit code range (section 11.2).
- One business that is both a customer and a supplier needs two unrelated
  ledgers, so netting (contra) is manual.
- Party data (221 columns) is mixed with accounting structure.

**Recommended (standard in SAP, Oracle, Dynamics, Odoo and IFRS-audited companies):**

```
Chart of Accounts (small, stable)              Party master (unlimited)
──────────────────────────────────              ─────────────────────────
110201 Trade Receivables  [control] ◄──────────  Customer C000001 (ABC LLC)
                                     ◄──────────  Customer C000002 (XYZ Trading)
210101 Trade Payables     [control] ◄──────────  Supplier S000001 (Dubai Steel)
```

- The GL has **one control account** per receivable or payable type: Trade
  Receivables, Trade Payables, Staff Receivables, Related Party, and so on.
- Every journal line posted to a control account must carry a `party_id`.
  Customer statements, aging and credit control are built from the journal
  lines filtered by `party_id`.
- Control accounts are flagged `allow_manual_posting = 0`. Only sales,
  purchase, receipt, payment and credit/debit note documents can post to them.
  This guarantees **sub-ledger total = control account balance**, which is a
  key audit check.
- A party can be a customer, a supplier, or both. Each role has its own
  control account, and the two can be netted by a contra entry.
- The control account is picked automatically from a **party group**
  (local vs. foreign customers, related parties, etc.). This replaces
  `parent_control` and gives one GL account per group.

**Example posting for a credit sale of AED 1,000 + 5% VAT to customer C000001:**

| Account | Party | Debit | Credit |
|---------|-------|------:|-------:|
| 110201 Trade Receivables (control) | C000001 | 1,050.00 | |
| 410101 Sales Revenue | | | 1,000.00 |
| 210501 VAT Output | | | 50.00 |

In the current ERP, the debit line would be posted to the customer's own ledger
under `00070`.

### 11.4 Structural and technical changes

| Area | Current (`infosblank`) | Recommended |
|------|------------------------|-------------|
| Primary key | `(company_code, ac_code varchar(5))` | `account_id BIGINT` + unique `(company_id, account_no)` |
| Hierarchy | String path `account_code`, `child_exist` char | `parent_id` + ID path / closure table, `is_group BIT`, `level_no` |
| Account class | One `account_type` (A/L/I/E), sometimes wrong or NULL | `account_class` (Asset, Liability, Equity, Income, Expense) plus `account_subtype` (Cash, Bank, Receivable, Payable, Inventory, FixedAsset, AccumDepreciation, VATInput, VATOutput, Equity, RetainedEarnings, Revenue, COGS, OpEx, Tax…) and a `normal_balance` (D/C) that cannot be NULL |
| Reporting | `control_tb` only says BS or PL | Map each account to a financial-statement line (IFRS), current vs. non-current, and a cash-flow category (operating / investing / financing) |
| Money types | `float` (`cr_limit`, `opening_dr`, `budget`…) | `DECIMAL(18,4)` for amounts and `DECIMAL(18,8)` for exchange rates. Float causes rounding differences, so trial balances fail to tie. |
| General ledger | Calculated on the fly by `view_account_ledger` (UNION of ~30 document types) | One posted **journal** (`journal_header` + `journal_line`) written when a document is approved. Every report reads only from it. Faster, auditable, and easy to reconcile. |
| Corrections | Documents can be edited after posting | Posted entries are **immutable**. Corrections are made with reversal entries, with a full audit trail (who, when, before/after). |
| Periods | None in the COA | `fiscal_year` and `fiscal_period` tables with Open / Closed / Locked status. Year-end closing posts P&L to retained earnings. |
| Branch / cost center / job | `branch_code` column, `costcenter_required` flag | **Dimensions** on journal lines (branch, cost center, department, project/job). Never create separate GL accounts per branch. Add dimension rules per account (required / optional / not allowed). |
| Posting control | Only `child_exist`, `active`, `blocked` | Also `allow_manual_posting`, `requires_party`, `allowed_currencies`, valid-from / valid-to dates |
| Party data | ~180 party columns inside `ac_master` | Separate `party`, `party_address`, `party_contact`, `party_bank`, `party_tax` tables |
| Multi-currency | `currency_code` on the account only | Transaction and base currency on every journal line, plus period-end **revaluation** of foreign-currency balances posted to unrealised FX gain/loss accounts |
| Multi-company | `company_code` on every row, a separate COA per company | One **COA template** copied to new companies, a group mapping for consolidation, and inter-company accounts that are mirrored automatically |
| Budgets | `budget` column on the account (unused) | `budget(account_id, period_id, dimension…, amount)` table |
| Governance | Anyone with access can create accounts | Maker-checker approval for new or changed accounts, role-based access, `confidential` accounts (payroll) restricted |
| Opening balances | `opening_balance` table | Opening balance journal (doc type `OB`) against a temporary *Opening Balance Equity* account that must be zero after go-live |

### 11.5 Accounting corrections to the current basic chart

1. **Make Equity its own head.** Equity is not a liability. Use the heads
   Assets, Liabilities, Equity, Revenue, Cost of Sales, Operating Expenses, and
   Other Income/Expense & Tax. Mapping to IFRS statements is then direct.
2. **Choose one inventory method: perpetual.** The current chart mixes the
   perpetual method (INVENTORY-PURCHASE / -SALES / -ADJUSTMENT and COST OF
   STOCK-* ledgers) with the periodic method (PURCHASE EXPENSE accounts and
   `STOCK IN HAND (P&L)` under income). In a perpetual system:
   - Purchases debit **Inventory**.
   - Sales debit **COGS** and credit **Inventory**, at item cost.
   - Movement types (purchase, sale, transfer, adjustment) are tracked in the
     **stock ledger**, not in separate GL accounts.

   One inventory account per category or location is enough.
3. **One Sales Revenue account, not Cash Sales / Credit Sales.** Cash vs.
   credit is a payment method, not a type of revenue. Keep Sales Returns and
   Sales Discounts as contra-revenue accounts.
4. **Split Retained Earnings** into *Retained Earnings (prior years)* and
   *Current Year Profit/Loss* (system-calculated). Add *Statutory Reserve*
   (UAE Commercial Companies Law transfer from net profit), *Share Capital*, and
   *Partners' Current / Drawings*.
5. **Contra accounts.** Add *Accumulated Depreciation* per fixed-asset class
   and *Allowance for Doubtful Debts (ECL)*. Never reduce asset cost directly.
6. **Accruals and provisions that are missing:**
   - Accrued Expenses
   - Advances from Customers
   - Advances to Suppliers
   - **Provision for End-of-Service Benefits (gratuity)**, which is mandatory for
     UAE employers
   - Leave Salary Provision
   - Air Ticket Provision
   - **GRNI (Goods Received Not Invoiced)**, because the ERP has a GRN flow
7. **VAT.** Keep Input VAT and Output VAT, and add:
   - *VAT Payable/Refundable (net)*, cleared at each return
   - *Reverse Charge VAT* (imports and services from abroad)
   - A **tax code table** (Standard 5%, Zero-rated, Exempt, Out of scope,
     Reverse charge) that drives the account and maps to the FTA VAT return
     boxes
8. **UAE Corporate Tax (9%).**
   - Add *Corporate Tax Payable*, *Current Tax Expense*, and *Deferred Tax
     Asset/Liability*.
   - Flag expense accounts with their CT treatment, e.g. entertainment is 50%
     non-deductible and fines/penalties are non-deductible. The tax adjustment
     schedule can then be generated automatically.
9. **Others:**
   - *Suspense Account*, which must be zero at period end
   - *Rounding Difference*
   - *Realised and Unrealised FX Gain/Loss*
   - *Bank Charges*
   - *Gain/Loss on Disposal of Fixed Assets*
   - *Inter-branch / Inter-company* accounts
   - *Right-of-Use Assets* and *Lease Liabilities* (IFRS 16, for office and
     warehouse leases)
10. **Fix the data issues from section 9** (`00085` path, `00030`/`00031`
    typed as Income, `00157` with NULL type) during migration. Don't migrate
    them as-is.

### 11.6 Suggested starter Chart of Accounts for the new ERP

The numbering is hierarchical and meaningful: 1 digit for the head, 2 for the
group, 4 for the sub-group, 6 for the ledger. Leave gaps so accounts can be
inserted later. `[C]` = control account (party required, no manual posting).
`[X]` = contra account.

```
1      ASSETS
11       Current Assets
1101       Cash & Cash Equivalents
110101       Cash on Hand
110102       Petty Cash
110110       Bank - <Bank name> AED          (one ledger per bank account)
110111       Bank - <Bank name> USD
1102       Trade Receivables
110201       Trade Receivables - Customers   [C]
110202       Allowance for Doubtful Debts    [X]
110203       PDC Received
1103       Other Receivables & Advances
110301       Staff Advances                  [C]
110302       Advances to Suppliers           [C]
110303       Prepaid Expenses
110304       Deposits (short term)
1104       Inventory
110401       Inventory - Trading Goods
110402       Goods in Transit
1105       Tax Assets
110501       VAT Input (Recoverable)
110502       VAT Refundable
12       Non-Current Assets
1201       Property, Plant & Equipment - Cost
120101       Furniture & Fixtures
120102       Office Equipment
120103       Vehicles
120104       Buildings
1202       Accumulated Depreciation          [X]
120201       Acc. Dep. - Furniture & Fixtures
120202       Acc. Dep. - Office Equipment
120203       Acc. Dep. - Vehicles
120204       Acc. Dep. - Buildings
1203       Right-of-Use Assets
1204       Intangible Assets
1205       Refundable Deposits (long term)

2      LIABILITIES
21       Current Liabilities
2101       Trade Payables
210101       Trade Payables - Suppliers      [C]
210102       PDC Issued
2102       Accruals & Advances
210201       Accrued Expenses
210202       Advances from Customers         [C]
210203       GRNI - Goods Received Not Invoiced
2103       Tax Liabilities
210301       VAT Output
210302       VAT Payable (net)
210303       Reverse Charge VAT
210304       Corporate Tax Payable
2104       Short-term Borrowings
210401       Bank Overdraft
210402       Short-term Loans
2105       Employee Payables
210501       Salaries Payable
210502       Leave Salary Provision
210503       Air Ticket Provision
22       Non-Current Liabilities
2201       Provision for End-of-Service Benefits
2202       Long-term Loans
2203       Lease Liabilities
2204       Deferred Tax Liability

3      EQUITY
3101     Share Capital
3102     Statutory Reserve
3103     Retained Earnings - Prior Years
3104     Current Year Profit / Loss          (system, no manual posting)
3105     Partners' Current Account / Drawings
3199     Opening Balance Equity              (temporary, must be zero after go-live)

4      REVENUE
4101     Sales Revenue - Goods
4102     Service Revenue
4103     Sales Returns                       [X]
4104     Sales Discounts                     [X]

5      COST OF SALES
5101     Cost of Goods Sold
5102     Freight Inwards
5103     Customs Duty
5104     Inventory Adjustments / Write-offs

6      OPERATING EXPENSES
61       Staff Costs
6101       Salaries & Wages
6102       End-of-Service Benefits Expense
6103       Leave Salary & Air Ticket Expense
6104       Visa, Labour & Medical Insurance
62       Premises
6201       Rent
6202       Utilities (DEWA / ADDC / SEWA)
63       Administrative
6301       Telephone & Internet
6302       Printing & Stationery
6303       Professional & Audit Fees
6304       Entertainment                     (CT: 50% non-deductible)
6305       Fines & Penalties                 (CT: non-deductible)
64       Depreciation & Amortisation
65       Selling & Distribution
66       Finance Costs
6601       Bank Charges
6602       Interest Expense
67       Bad Debts / ECL Expense

7      OTHER INCOME / EXPENSE & TAX
7101     Other Income
7102     Realised FX Gain / Loss
7103     Unrealised FX Gain / Loss
7104     Gain / Loss on Disposal of Fixed Assets
7105     Rounding Difference
7201     Corporate Tax Expense - Current
7202     Deferred Tax Expense

9      SYSTEM
9101     Suspense Account                    (must be zero at period end)
```

### 11.7 Recommended creation flows in the new ERP

**New GL account**

1. The user picks the parent group. The system suggests the next free
   `account_no` in that group's range.
2. Class, subtype, normal balance and statement mapping are inherited from the
   parent. The user can only narrow the subtype.
3. Posting rules are set: manual posting allowed?, party required?,
   dimensions required?, currency.
4. The account is saved as *Pending* and becomes *Active* after approval
   (maker-checker).
5. If the parent was a ledger with postings, creation is rejected. Create an
   "Others" child and reclassify the balance with a journal first.

**New customer / supplier**

1. Create the party (name, TRN, addresses, contacts, bank, credit limit /
   days, salesman, currency, payment terms). The party code comes from its own
   series (`C000001`, `S000001`).
2. Choose a party group. The receivable or payable control account comes from
   the group. **No new GL account is created.**
3. Documents (invoice, receipt, credit note…) post to the control account with
   `party_id` on the journal line.
4. Statements, aging, credit-limit checks and VAT reports come from journal
   lines grouped by `party_id`.
5. Run a month-end check that sub-ledger totals equal the control account
   balance.

### 11.8 Migrating from `infosblank` to the new ERP

1. **Accounts.** Map every old `ac_code` to a new `account_no` and
   `account_id`, and keep `legacy_ac_code` as a column for traceability.
   Apply the corrections from section 11.5.
2. **Customer and supplier ledgers.** Every ledger under the `parent_control`
   groups (`C` = `00070`, `S` = `00090`) becomes a **party**, not a GL account.
   Its balance moves to the control account with that `party_id`.
   - These rows are easy to find: they are the only rows with `parent_ac_code`
     filled (for example `parent_ac_code IN ('00070','00090')`).
   - Map `cr_amount` → credit limit, `cr_days` → credit days, `tax_no` → TRN,
     `ref_code` → legacy reference, and `ac_code` → `legacy_ac_code`.
   - Trim `description` while migrating.
3. **Opening balances.** Post one opening journal per company and branch at the
   cut-over date. Post open invoices individually for each party so aging stays
   correct.
4. **Reconcile.** The new trial balance must equal the old trial balance (by
   mapped account) and the party aging must equal the old aging before go-live.

### 11.9 Summary of decisions

| Decision | Recommendation |
|----------|----------------|
| Follow the current tree + leaf-posting concept? | **Yes** |
| Keep the 5-digit `ac_code` as the key? | **No.** Use a `BIGINT` ID plus a `VARCHAR(20)` display number. |
| Create a GL ledger per customer/supplier? | **No.** Use control accounts plus a party sub-ledger. |
| Keep Equity under Liability? | **No.** Make it a separate head. |
| Calculate the GL from a UNION view? | **No.** Post to an immutable journal table. |
| Use `float` for money? | **No.** Use `DECIMAL(18,4)`. |
| Branch / cost center as separate accounts? | **No.** Use dimensions on journal lines. |
| Add UAE VAT tax codes, Corporate Tax and EOSB accounts? | **Yes** |
| Add fiscal periods with locking and year-end close? | **Yes** |

> Tax rules (VAT, Corporate Tax deductibility, statutory reserve, record
> retention, e-invoicing timelines) change over time. Confirm the final tax
> account setup with your tax advisor before go-live.

---

## 12. Implementation Guide for the New Application (PostgreSQL + PHP Laravel)

> **START HERE to build the new application.** Sections 1–9 describe the old
> ERP, and section 11 explains the reasons behind this design. This section is
> the actual blueprint: database tables, triggers, Laravel structure, seed data,
> report queries, build order, and data migration.

### 12.1 Before you start

| Item | Requirement |
|------|-------------|
| Database | **PostgreSQL 15 or later.** The schema uses `UNIQUE NULLS NOT DISTINCT`; a workaround for older versions is given where it's used. |
| Backend | PHP 8.2+, Laravel 11 or later, `pdo_pgsql` enabled |
| `.env` | `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Money | Store as `numeric(18,4)` and cast to `decimal:4` in Eloquent, which returns a string. Do arithmetic with `bcmath` or `brick/math`. **Never use PHP `float`.** |
| Naming | Laravel conventions: plural `snake_case` table names, `id` primary keys, `<table>_id` foreign keys, `created_at` / `updated_at` |
| Multi-company | Every business table has `company_id`, and every query filters by it. A global Eloquent scope does this automatically (section 12.6). |

Recommended reading order inside this section:

1. **12.2** Table overview: what tables exist and how they connect.
2. **12.3** Full PostgreSQL DDL, including triggers.
3. **12.4** Seed data: account subtypes, the starter chart, system accounts,
   tax codes.
4. **12.5** Report queries: trial balance, party ledger, outstanding invoices,
   aging.
5. **12.6** Laravel structure: migrations, models, services, example code.
6. **12.7** Where each business rule is enforced.
7. **12.8** Build order (phases).
8. **12.9** Testing checklist.
9. **12.10** Migrating data from `infosblank`.

### 12.2 Table overview

```
                           ┌──────────────┐
                           │  companies   │──────────────┐
                           └──────┬───────┘              │
              ┌──────────────┬────┴─────────┬────────────┼──────────────┐
              ▼              ▼              ▼            ▼              ▼
         branches      fiscal_years    currencies    accounts ◄──┐   parties ─────────────┐
                            │          exchange_rates   │  │      │   │ party_addresses    │
                            ▼                           │  └parent┘   │ party_contacts     │
                      fiscal_periods                    │             │ party_bank_accounts│
                            │          account_subtypes─┤             │ party_customers ───┤──► accounts (AR control)
                            │          system_accounts ─┤             │ party_suppliers ───┤──► accounts (AP control)
                            │          tax_codes ───────┤             └─ party_groups ─────┘──► accounts (control)
                            │          account_budgets ─┤
                            ▼                           ▼
                        journals ──────────────► journal_lines ◄──── allocations (invoice line ↔ payment line)
                                                   │  │  │  │
                                  parties ◄────────┘  │  │  └────► tax_codes
                                  cost_centers ◄──────┘  └───────► projects
```

| Group | Tables | Purpose |
|-------|--------|---------|
| Foundation | `currencies`, `exchange_rates`, `companies`, `branches` | Companies, branches and currencies |
| **Chart of accounts** | `account_subtypes`, `accounts`, `system_accounts` | The account tree, reporting classification, and default accounts for automatic postings (replaces `ac_master`, `control_tb`, `control_table`) |
| Periods | `fiscal_years`, `fiscal_periods` | Open / closed / locked periods |
| Dimensions | `cost_centers`, `projects` | Analysis on journal lines (instead of extra accounts) |
| Tax | `tax_codes` | UAE VAT codes linked to VAT accounts and return boxes |
| **Parties** | `party_groups`, `parties`, `party_addresses`, `party_contacts`, `party_bank_accounts`, `party_customers`, `party_suppliers` | Customers and suppliers (replaces the party columns of `ac_master` and `parent_control`) |
| **General ledger** | `journals`, `journal_lines`, `allocations` | The posted journal, which is the single source of truth (replaces `view_account_ledger`), plus invoice/payment matching |
| Planning | `account_budgets` | Budget per account per period |
| Utilities | `number_series` | Document and party numbering |

### 12.3 PostgreSQL schema (DDL)

This is the reference schema. In Laravel, create each block as a migration
(section 12.6). Laravel's schema builder handles tables, columns, foreign keys
and indexes. Use `DB::statement()` for `CHECK` constraints and special indexes,
and `DB::unprepared()` for the trigger functions.

`users` is Laravel's default table. `created_by`, `updated_by`, `approved_by`
and `posted_by` reference `users(id)`.

#### 12.3.1 Foundation

```sql
CREATE TABLE currencies (
    code            char(3)      PRIMARY KEY,               -- ISO 4217: AED, USD, EUR
    name            varchar(50)  NOT NULL,
    decimal_places  smallint     NOT NULL DEFAULT 2,
    is_active       boolean      NOT NULL DEFAULT true
);

CREATE TABLE companies (
    id                       bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code                     varchar(10)  NOT NULL UNIQUE,
    name                     varchar(150) NOT NULL,
    name_ar                  varchar(150),
    trn                      varchar(20),
    base_currency            char(3)      NOT NULL DEFAULT 'AED' REFERENCES currencies(code),
    fiscal_year_start_month  smallint     NOT NULL DEFAULT 1 CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
    is_active                boolean      NOT NULL DEFAULT true,
    created_at               timestamptz,
    updated_at               timestamptz
);

CREATE TABLE exchange_rates (
    id             bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id     bigint        NOT NULL REFERENCES companies(id),
    currency_code  char(3)       NOT NULL REFERENCES currencies(code),
    rate_date      date          NOT NULL,
    rate           numeric(18,8) NOT NULL CHECK (rate > 0),   -- 1 unit of currency = rate x base currency
    created_at     timestamptz,
    updated_at     timestamptz,
    UNIQUE (company_id, currency_code, rate_date)
);

CREATE TABLE branches (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id  bigint       NOT NULL REFERENCES companies(id),
    code        varchar(10)  NOT NULL,
    name        varchar(150) NOT NULL,
    name_ar     varchar(150),
    emirate     varchar(30),
    is_active   boolean      NOT NULL DEFAULT true,
    created_at  timestamptz,
    updated_at  timestamptz,
    UNIQUE (company_id, code)
);
```

#### 12.3.2 Chart of accounts

```sql
CREATE TABLE account_subtypes (
    id                  bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code                varchar(40)  NOT NULL UNIQUE,         -- cash, bank, receivable, cogs ...
    name                varchar(100) NOT NULL,
    account_class       varchar(10)  NOT NULL
                        CHECK (account_class IN ('asset','liability','equity','income','expense')),
    is_contra           boolean      NOT NULL DEFAULT false,
    cash_flow_category  varchar(10)
                        CHECK (cash_flow_category IN ('cash','operating','investing','financing')),
    sort_order          integer      NOT NULL DEFAULT 0
);

CREATE TABLE accounts (
    id                    bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id            bigint       NOT NULL REFERENCES companies(id),
    parent_id             bigint       REFERENCES accounts(id),
    account_no            varchar(20)  NOT NULL,              -- user-facing number, e.g. 110201
    name                  varchar(150) NOT NULL,
    name_ar               varchar(150),

    -- Classification. account_class is inherited from the parent when NULL on insert;
    -- statement_type and normal_balance are always set by the trigger.
    account_class         varchar(10)  NOT NULL
                          CHECK (account_class IN ('asset','liability','equity','income','expense')),
    statement_type        char(2)      NOT NULL CHECK (statement_type IN ('BS','PL')),
    normal_balance        char(1)      NOT NULL CHECK (normal_balance IN ('D','C')),
    account_subtype_id    bigint       REFERENCES account_subtypes(id),
    is_contra             boolean      NOT NULL DEFAULT false,
    cash_flow_category    varchar(10)  CHECK (cash_flow_category IN ('cash','operating','investing','financing')),
    ct_treatment          varchar(15)  NOT NULL DEFAULT 'deductible'
                          CHECK (ct_treatment IN ('deductible','partial_50','non_deductible','exempt')),

    -- Tree. level and path are maintained by triggers only. is_group becomes true
    -- automatically when a child is added; set it manually only for an empty group.
    is_group              boolean      NOT NULL DEFAULT false,
    level                 smallint     NOT NULL DEFAULT 0,
    path                  varchar(900) NOT NULL DEFAULT '',   -- ids from the root: /1/5/23/

    -- Posting rules
    is_control            boolean      NOT NULL DEFAULT false, -- party required on every line
    control_type          varchar(10)  CHECK (control_type IN ('customer','supplier','employee','other')),
    allow_manual_posting  boolean      NOT NULL DEFAULT true,  -- false = only system documents may post
    cost_center_rule      varchar(10)  NOT NULL DEFAULT 'optional'
                          CHECK (cost_center_rule IN ('none','optional','required')),
    project_rule          varchar(10)  NOT NULL DEFAULT 'optional'
                          CHECK (project_rule IN ('none','optional','required')),
    currency_code         char(3)      REFERENCES currencies(code), -- NULL = any currency

    -- Status and governance
    status                varchar(10)  NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending','active','inactive')),
    is_blocked            boolean      NOT NULL DEFAULT false,
    is_confidential       boolean      NOT NULL DEFAULT false,
    approved_by           bigint       REFERENCES users(id),
    approved_at           timestamptz,
    legacy_ac_code        varchar(10),                         -- ac_code from infosblank
    created_by            bigint       REFERENCES users(id),
    updated_by            bigint       REFERENCES users(id),
    created_at            timestamptz,
    updated_at            timestamptz,

    CONSTRAINT accounts_company_no_unique UNIQUE (company_id, account_no),
    CONSTRAINT accounts_control_type_chk  CHECK (is_control = false OR control_type IS NOT NULL),
    CONSTRAINT accounts_group_control_chk CHECK (NOT (is_group AND is_control))
);

CREATE INDEX accounts_parent_idx ON accounts (parent_id);
CREATE INDEX accounts_path_idx   ON accounts (company_id, path varchar_pattern_ops);  -- fast LIKE '/1/5/%'

CREATE TABLE system_accounts (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id  bigint      NOT NULL REFERENCES companies(id),
    branch_id   bigint      REFERENCES branches(id),           -- NULL = company-wide default
    key         varchar(50) NOT NULL,                          -- vat_output, cogs, retained_earnings ...
    account_id  bigint      NOT NULL REFERENCES accounts(id),
    created_at  timestamptz,
    updated_at  timestamptz,
    CONSTRAINT system_accounts_unique UNIQUE NULLS NOT DISTINCT (company_id, branch_id, key)
    -- PostgreSQL < 15: instead use
    -- CREATE UNIQUE INDEX ... ON system_accounts (company_id, COALESCE(branch_id, 0), key);
);
```

**Account tree triggers.** These keep `path`, `level`, `is_group`,
`statement_type` and `normal_balance` correct no matter which screen, import or
script writes to `accounts`. The function body references `journal_lines`;
PL/pgSQL resolves that at run time, so the function can be created before the
`journal_lines` table exists.

```sql
CREATE OR REPLACE FUNCTION accounts_before_write() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    p accounts%ROWTYPE;
BEGIN
    IF NEW.parent_id IS NOT NULL THEN
        SELECT * INTO p FROM accounts WHERE id = NEW.parent_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Parent account % does not exist', NEW.parent_id;
        END IF;
        IF p.company_id <> NEW.company_id THEN
            RAISE EXCEPTION 'Parent account % belongs to another company', p.account_no;
        END IF;
        IF NEW.account_class IS NULL THEN
            NEW.account_class := p.account_class;
        END IF;
    END IF;

    -- Path and level: only on insert or when the account is moved
    IF TG_OP = 'INSERT' OR NEW.parent_id IS DISTINCT FROM OLD.parent_id THEN
        IF NEW.parent_id IS NULL THEN
            NEW.level := 0;
            NEW.path  := '/' || NEW.id || '/';
        ELSE
            IF TG_OP = 'UPDATE' AND p.path LIKE OLD.path || '%' THEN
                RAISE EXCEPTION 'Account % cannot be moved under itself or one of its descendants', NEW.account_no;
            END IF;
            IF NOT p.is_group AND EXISTS (SELECT 1 FROM journal_lines WHERE account_id = p.id) THEN
                RAISE EXCEPTION 'Account % has postings and cannot become a group. Create an "Others" ledger and reclassify its balance first.', p.account_no;
            END IF;
            NEW.level := p.level + 1;
            NEW.path  := p.path || NEW.id || '/';
        END IF;
    END IF;

    IF TG_OP = 'UPDATE' AND NEW.account_class <> OLD.account_class
       AND (EXISTS (SELECT 1 FROM accounts WHERE parent_id = NEW.id)
            OR EXISTS (SELECT 1 FROM journal_lines WHERE account_id = NEW.id)) THEN
        RAISE EXCEPTION 'Class of account % cannot change: it has children or postings', NEW.account_no;
    END IF;

    NEW.statement_type := CASE WHEN NEW.account_class IN ('asset','liability','equity') THEN 'BS' ELSE 'PL' END;
    NEW.normal_balance := CASE
        WHEN NEW.account_class IN ('asset','expense') THEN CASE WHEN NEW.is_contra THEN 'C' ELSE 'D' END
        ELSE CASE WHEN NEW.is_contra THEN 'D' ELSE 'C' END
    END;

    -- Balance-sheet children keep the parent's class; P&L heads may mix income and expense
    IF NEW.parent_id IS NOT NULL
       AND (p.statement_type <> NEW.statement_type
            OR (p.statement_type = 'BS' AND p.account_class <> NEW.account_class)) THEN
        RAISE EXCEPTION 'Account % (%) does not fit under parent % (%)',
            NEW.account_no, NEW.account_class, p.account_no, p.account_class;
    END IF;

    IF TG_OP = 'UPDATE' AND NEW.is_group AND NOT OLD.is_group
       AND EXISTS (SELECT 1 FROM journal_lines WHERE account_id = NEW.id) THEN
        RAISE EXCEPTION 'Account % has postings and cannot become a group', NEW.account_no;
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.is_group AND NOT NEW.is_group
       AND EXISTS (SELECT 1 FROM accounts WHERE parent_id = NEW.id) THEN
        RAISE EXCEPTION 'Account % still has children and must stay a group', NEW.account_no;
    END IF;

    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION accounts_after_write() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- The parent becomes a group in the same transaction (the old ERP did not do this)
    IF NEW.parent_id IS NOT NULL THEN
        UPDATE accounts SET is_group = true, updated_at = now()
         WHERE id = NEW.parent_id AND is_group = false;
    END IF;

    -- Moving an account rewrites the path of all its descendants
    IF TG_OP = 'UPDATE' AND NEW.path <> OLD.path THEN
        UPDATE accounts
           SET path       = NEW.path || substr(path, length(OLD.path) + 1),
               level      = level + (NEW.level - OLD.level),
               updated_at = now()
         WHERE company_id = NEW.company_id
           AND path LIKE OLD.path || '%'
           AND id <> NEW.id;
    END IF;

    RETURN NULL;
END $$;

CREATE TRIGGER accounts_before_write
    BEFORE INSERT OR UPDATE ON accounts
    FOR EACH ROW EXECUTE FUNCTION accounts_before_write();

-- Fires only on insert or parent change, so the descendant path update does not re-trigger it
CREATE TRIGGER accounts_after_write
    AFTER INSERT OR UPDATE OF parent_id ON accounts
    FOR EACH ROW EXECUTE FUNCTION accounts_after_write();
```

#### 12.3.3 Fiscal periods

```sql
CREATE TABLE fiscal_years (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id  bigint      NOT NULL REFERENCES companies(id),
    name        varchar(20) NOT NULL,                          -- FY2027
    start_date  date        NOT NULL,
    end_date    date        NOT NULL,
    status      varchar(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
    closed_by   bigint      REFERENCES users(id),
    closed_at   timestamptz,
    created_at  timestamptz,
    updated_at  timestamptz,
    UNIQUE (company_id, name),
    CHECK (end_date > start_date)
);

CREATE TABLE fiscal_periods (
    id              bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id      bigint      NOT NULL REFERENCES companies(id),
    fiscal_year_id  bigint      NOT NULL REFERENCES fiscal_years(id),
    period_no       smallint    NOT NULL CHECK (period_no BETWEEN 1 AND 13), -- 13 = year-end adjustments
    start_date      date        NOT NULL,
    end_date        date        NOT NULL,
    status          varchar(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed','locked')),
    created_at      timestamptz,
    updated_at      timestamptz,
    UNIQUE (fiscal_year_id, period_no),
    CHECK (end_date >= start_date)
);
CREATE INDEX fiscal_periods_dates_idx ON fiscal_periods (company_id, start_date, end_date);
```

Period status meanings (the journal trigger only allows posting into `open` periods):
- `open`: users with posting permission can post.
- `closed`: no posting. A user with the "reopen period" permission can set it
  back to `open` to post a late adjustment, then close it again. Log every
  reopen.
- `locked`: no posting and it can't be reopened. Use this after the year has
  been audited and the VAT/CT returns are filed.

#### 12.3.4 Dimensions and tax

```sql
CREATE TABLE cost_centers (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id  bigint       NOT NULL REFERENCES companies(id),
    parent_id   bigint       REFERENCES cost_centers(id),
    code        varchar(20)  NOT NULL,
    name        varchar(150) NOT NULL,
    is_group    boolean      NOT NULL DEFAULT false,
    is_active   boolean      NOT NULL DEFAULT true,
    created_at  timestamptz,
    updated_at  timestamptz,
    UNIQUE (company_id, code)
);

CREATE TABLE tax_codes (
    id                       bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id               bigint       NOT NULL REFERENCES companies(id),
    code                     varchar(10)  NOT NULL,             -- SR, ZR, EX, OOS, RCM
    name                     varchar(100) NOT NULL,
    rate                     numeric(7,4) NOT NULL DEFAULT 0,   -- 5.0000 = 5 %
    tax_category             varchar(15)  NOT NULL
                             CHECK (tax_category IN ('standard','zero','exempt','out_of_scope','reverse_charge')),
    applies_to               varchar(10)  NOT NULL CHECK (applies_to IN ('sales','purchase','both')),
    output_account_id        bigint       REFERENCES accounts(id), -- VAT Output / RCM output
    input_account_id         bigint       REFERENCES accounts(id), -- VAT Input
    vat_return_box_sales     varchar(10),                         -- FTA VAT201 box for sales
    vat_return_box_purchase  varchar(10),                         -- FTA VAT201 box for purchases
    is_active                boolean      NOT NULL DEFAULT true,
    created_at               timestamptz,
    updated_at               timestamptz,
    UNIQUE (company_id, code)
);
```

`projects` references `parties`, so it is created after the party tables (12.3.5).

#### 12.3.5 Parties (customers and suppliers)

```sql
CREATE TABLE party_groups (
    id                  bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id          bigint       NOT NULL REFERENCES companies(id),
    code                varchar(20)  NOT NULL,                 -- LOCAL-CUST, FOREIGN-SUPP ...
    name                varchar(100) NOT NULL,
    role                varchar(10)  NOT NULL CHECK (role IN ('customer','supplier')),
    control_account_id  bigint       NOT NULL REFERENCES accounts(id),  -- replaces parent_control
    is_default          boolean      NOT NULL DEFAULT false,
    created_at          timestamptz,
    updated_at          timestamptz,
    UNIQUE (company_id, code)
);

CREATE TABLE parties (
    id                    bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id            bigint       NOT NULL REFERENCES companies(id),
    party_code            varchar(20)  NOT NULL,               -- P000001 (from number_series)
    party_name            varchar(150) NOT NULL,
    party_name_ar         varchar(150),
    party_kind            varchar(10)  NOT NULL DEFAULT 'company'
                          CHECK (party_kind IN ('company','individual')),
    trn                   varchar(20),                          -- VAT registration number
    trade_license_no      varchar(50),
    trade_license_expiry  date,
    currency_code         char(3)      REFERENCES currencies(code),
    email                 varchar(150),
    phone                 varchar(30),
    website               varchar(150),
    is_active             boolean      NOT NULL DEFAULT true,
    legacy_ac_code        varchar(10),                          -- ac_code from infosblank
    created_by            bigint       REFERENCES users(id),
    updated_by            bigint       REFERENCES users(id),
    created_at            timestamptz,
    updated_at            timestamptz,
    deleted_at            timestamptz,                          -- soft delete
    UNIQUE (company_id, party_code)
);
CREATE INDEX parties_name_idx ON parties (company_id, lower(party_name));
CREATE INDEX parties_trn_idx  ON parties (company_id, trn) WHERE trn IS NOT NULL;

CREATE TABLE party_addresses (
    id            bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    party_id      bigint       NOT NULL REFERENCES parties(id) ON DELETE CASCADE,
    address_type  varchar(10)  NOT NULL DEFAULT 'billing'
                  CHECK (address_type IN ('billing','delivery','site','other')),
    line1         varchar(250) NOT NULL,
    line2         varchar(250),
    street        varchar(250),
    landmark      varchar(250),
    city          varchar(50),
    emirate       varchar(30),
    po_box        varchar(20),
    country_code  char(2)      NOT NULL DEFAULT 'AE',
    is_default    boolean      NOT NULL DEFAULT false,
    created_at    timestamptz,
    updated_at    timestamptz
);

CREATE TABLE party_contacts (
    id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    party_id     bigint       NOT NULL REFERENCES parties(id) ON DELETE CASCADE,
    name         varchar(100) NOT NULL,
    designation  varchar(50),
    phone        varchar(30),
    mobile       varchar(30),
    email        varchar(150),
    is_primary   boolean      NOT NULL DEFAULT false,
    created_at   timestamptz,
    updated_at   timestamptz
);

CREATE TABLE party_bank_accounts (
    id             bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    party_id       bigint       NOT NULL REFERENCES parties(id) ON DELETE CASCADE,
    bank_name      varchar(100) NOT NULL,
    branch_name    varchar(100),
    account_name   varchar(150),
    account_no     varchar(50),
    iban           varchar(34),
    swift_code     varchar(11),
    currency_code  char(3)      REFERENCES currencies(code),
    is_default     boolean      NOT NULL DEFAULT false,
    created_at     timestamptz,
    updated_at     timestamptz
);

CREATE TABLE party_customers (
    party_id             bigint        PRIMARY KEY REFERENCES parties(id) ON DELETE CASCADE,
    party_group_id       bigint        NOT NULL REFERENCES party_groups(id),
    ar_account_id        bigint        NOT NULL REFERENCES accounts(id), -- defaults from the group
    credit_limit         numeric(18,4),
    credit_days          integer,
    salesman_id          bigint,                                        -- FK to your salesmen table
    price_level          smallint,
    default_tax_code_id  bigint        REFERENCES tax_codes(id),
    location_code        varchar(10),
    is_blocked           boolean       NOT NULL DEFAULT false,
    block_reason         varchar(250),
    created_at           timestamptz,
    updated_at           timestamptz
);

CREATE TABLE party_suppliers (
    party_id             bigint       PRIMARY KEY REFERENCES parties(id) ON DELETE CASCADE,
    party_group_id       bigint       NOT NULL REFERENCES party_groups(id),
    ap_account_id        bigint       NOT NULL REFERENCES accounts(id),
    credit_days          integer,
    payment_terms        varchar(250),
    default_tax_code_id  bigint       REFERENCES tax_codes(id),
    is_blocked           boolean      NOT NULL DEFAULT false,
    block_reason         varchar(250),
    created_at           timestamptz,
    updated_at           timestamptz
);

CREATE TABLE projects (
    id          bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id  bigint       NOT NULL REFERENCES companies(id),
    code        varchar(30)  NOT NULL,
    name        varchar(150) NOT NULL,
    party_id    bigint       REFERENCES parties(id),
    start_date  date,
    end_date    date,
    status      varchar(10)  NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
    created_at  timestamptz,
    updated_at  timestamptz,
    UNIQUE (company_id, code)
);
```

#### 12.3.6 Numbering

```sql
CREATE TABLE number_series (
    id              bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id      bigint      NOT NULL REFERENCES companies(id),
    branch_id       bigint      REFERENCES branches(id),
    doc_type        varchar(10) NOT NULL,                      -- JV, SI, RV, PV, PARTY ...
    fiscal_year_id  bigint      REFERENCES fiscal_years(id),   -- NULL = never resets
    prefix          varchar(10) NOT NULL DEFAULT '',
    next_number     bigint      NOT NULL DEFAULT 1,
    padding         smallint    NOT NULL DEFAULT 6,
    created_at      timestamptz,
    updated_at      timestamptz,
    CONSTRAINT number_series_unique UNIQUE NULLS NOT DISTINCT (company_id, branch_id, doc_type, fiscal_year_id)
);
```

#### 12.3.7 General ledger: journals, lines, allocations

```sql
CREATE TABLE journals (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id        bigint        NOT NULL REFERENCES companies(id),
    branch_id         bigint        REFERENCES branches(id),
    fiscal_period_id  bigint        NOT NULL REFERENCES fiscal_periods(id),
    journal_no        varchar(30)   NOT NULL,
    journal_date      date          NOT NULL,
    source_type       varchar(10)   NOT NULL,   -- JV, OB, SI, SR, PI, PR, RV, PV, CN, DN, FXR, YC, REV (PHP enum)
    source_id         bigint,                   -- id of the document in its module table
    reference         varchar(50),
    narration         text,
    currency_code     char(3)       NOT NULL REFERENCES currencies(code),
    exchange_rate     numeric(18,8) NOT NULL DEFAULT 1 CHECK (exchange_rate > 0),
    status            varchar(10)   NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','posted','reversed')),
    reversal_of_id    bigint        REFERENCES journals(id),
    posted_by         bigint        REFERENCES users(id),
    posted_at         timestamptz,
    created_by        bigint        REFERENCES users(id),
    updated_by        bigint        REFERENCES users(id),
    created_at        timestamptz,
    updated_at        timestamptz,
    UNIQUE (company_id, journal_no)
);
CREATE INDEX journals_source_idx ON journals (source_type, source_id);
CREATE INDEX journals_date_idx   ON journals (company_id, journal_date);

CREATE TABLE journal_lines (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    journal_id        bigint        NOT NULL REFERENCES journals(id) ON DELETE CASCADE,
    company_id        bigint        NOT NULL REFERENCES companies(id),
    line_no           smallint      NOT NULL,
    journal_date      date          NOT NULL,             -- copy of journals.journal_date (set on post)
    account_id        bigint        NOT NULL REFERENCES accounts(id),
    party_id          bigint        REFERENCES parties(id),
    branch_id         bigint        REFERENCES branches(id),
    cost_center_id    bigint        REFERENCES cost_centers(id),
    project_id        bigint        REFERENCES projects(id),
    tax_code_id       bigint        REFERENCES tax_codes(id),
    description       varchar(250),
    debit             numeric(18,4) NOT NULL DEFAULT 0,   -- base currency (AED)
    credit            numeric(18,4) NOT NULL DEFAULT 0,
    fc_currency_code  char(3)       REFERENCES currencies(code),
    fc_debit          numeric(18,4) NOT NULL DEFAULT 0,   -- transaction currency
    fc_credit         numeric(18,4) NOT NULL DEFAULT 0,
    exchange_rate     numeric(18,8) NOT NULL DEFAULT 1,
    due_date          date,                               -- invoices: date + credit days
    created_at        timestamptz,
    updated_at        timestamptz,
    CONSTRAINT journal_lines_amount_chk CHECK (
        debit >= 0 AND credit >= 0
        AND NOT (debit > 0 AND credit > 0)
        AND (debit > 0 OR credit > 0)
    ),
    UNIQUE (journal_id, line_no)
);
CREATE INDEX journal_lines_account_idx ON journal_lines (company_id, account_id, journal_date);
CREATE INDEX journal_lines_party_idx   ON journal_lines (company_id, party_id, account_id, journal_date)
    WHERE party_id IS NOT NULL;

CREATE TABLE allocations (
    id               bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id       bigint        NOT NULL REFERENCES companies(id),
    party_id         bigint        NOT NULL REFERENCES parties(id),
    account_id       bigint        NOT NULL REFERENCES accounts(id),       -- the control account
    invoice_line_id  bigint        NOT NULL REFERENCES journal_lines(id),  -- line that opened the item
    payment_line_id  bigint        NOT NULL REFERENCES journal_lines(id),  -- receipt / payment / credit note line
    amount           numeric(18,4) NOT NULL CHECK (amount > 0),
    allocated_on     date          NOT NULL,
    created_by       bigint        REFERENCES users(id),
    created_at       timestamptz,
    updated_at       timestamptz,
    CHECK (invoice_line_id <> payment_line_id)
);
CREATE INDEX allocations_invoice_idx ON allocations (invoice_line_id);
CREATE INDEX allocations_payment_idx ON allocations (payment_line_id);
CREATE INDEX allocations_party_idx   ON allocations (company_id, party_id);
```

For customers, the "invoice line" is a **debit** on Trade Receivables. For
suppliers, it is a **credit** on Trade Payables.

**Journal triggers.** These make the ledger safe even if a bug in the
application tries to save a bad entry:
- posted journals can't be changed or deleted
- a journal must balance before it's posted
- the period must be open
- no posting to group, inactive or blocked accounts
- a party is mandatory on control accounts
- cost center and project rules are enforced

```sql
CREATE OR REPLACE FUNCTION journals_validate() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    total_dr    numeric(18,4);
    total_cr    numeric(18,4);
    line_count  integer;
    p           fiscal_periods%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'Posted journal % cannot be deleted. Post a reversal instead.', OLD.journal_no;
        END IF;
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' THEN
        IF NOT (OLD.status = 'posted' AND NEW.status = 'reversed') THEN
            RAISE EXCEPTION 'Posted journal % cannot be changed. Post a reversal instead.', OLD.journal_no;
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.status = 'reversed' THEN
        RAISE EXCEPTION 'Only a posted journal can be marked as reversed';
    END IF;

    IF NEW.status = 'posted' THEN
        IF TG_OP = 'INSERT' THEN
            RAISE EXCEPTION 'Create the journal as draft, add its lines, then post it';
        END IF;

        SELECT * INTO p FROM fiscal_periods WHERE id = NEW.fiscal_period_id;
        IF p.company_id <> NEW.company_id THEN
            RAISE EXCEPTION 'Fiscal period belongs to another company';
        END IF;
        IF p.status <> 'open' THEN
            RAISE EXCEPTION 'Fiscal period is %; posting is not allowed', p.status;
        END IF;
        IF NEW.journal_date NOT BETWEEN p.start_date AND p.end_date THEN
            RAISE EXCEPTION 'Journal date % is outside the fiscal period', NEW.journal_date;
        END IF;

        UPDATE journal_lines SET journal_date = NEW.journal_date
         WHERE journal_id = NEW.id AND journal_date <> NEW.journal_date;

        SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0), COUNT(*)
          INTO total_dr, total_cr, line_count
          FROM journal_lines WHERE journal_id = NEW.id;

        IF line_count < 2 THEN
            RAISE EXCEPTION 'Journal % needs at least two lines', NEW.journal_no;
        END IF;
        IF total_dr <> total_cr THEN
            RAISE EXCEPTION 'Journal % is not balanced: debit % / credit %', NEW.journal_no, total_dr, total_cr;
        END IF;

        NEW.posted_at := COALESCE(NEW.posted_at, now());
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER journals_validate
    BEFORE INSERT OR UPDATE OR DELETE ON journals
    FOR EACH ROW EXECUTE FUNCTION journals_validate();

CREATE OR REPLACE FUNCTION journal_lines_validate() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    j_status  varchar(10);
    a         accounts%ROWTYPE;
BEGIN
    SELECT status INTO j_status FROM journals
     WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.journal_id ELSE NEW.journal_id END;

    IF TG_OP = 'DELETE' THEN
        -- j_status IS NULL: cascade from deleting a draft journal (journals_validate allowed it)
        IF j_status IS NOT NULL AND j_status <> 'draft' THEN
            RAISE EXCEPTION 'Lines of a posted journal cannot be deleted. Post a reversal instead.';
        END IF;
        RETURN OLD;
    END IF;

    IF j_status IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'Lines of a posted journal cannot be changed. Post a reversal instead.';
    END IF;

    SELECT * INTO a FROM accounts WHERE id = NEW.account_id;
    IF a.company_id <> NEW.company_id THEN
        RAISE EXCEPTION 'Account % belongs to another company', a.account_no;
    END IF;
    IF a.is_group THEN
        RAISE EXCEPTION 'Account % is a group. Post to a ledger under it.', a.account_no;
    END IF;
    IF a.status <> 'active' OR a.is_blocked THEN
        RAISE EXCEPTION 'Account % is not active or is blocked', a.account_no;
    END IF;
    IF a.is_control AND NEW.party_id IS NULL THEN
        RAISE EXCEPTION 'Account % is a control account. Select a customer/supplier.', a.account_no;
    END IF;
    IF a.cost_center_rule = 'required' AND NEW.cost_center_id IS NULL THEN
        RAISE EXCEPTION 'Account % requires a cost center', a.account_no;
    END IF;
    IF a.cost_center_rule = 'none' AND NEW.cost_center_id IS NOT NULL THEN
        RAISE EXCEPTION 'Account % does not accept a cost center', a.account_no;
    END IF;
    IF a.project_rule = 'required' AND NEW.project_id IS NULL THEN
        RAISE EXCEPTION 'Account % requires a project', a.account_no;
    END IF;
    IF a.project_rule = 'none' AND NEW.project_id IS NOT NULL THEN
        RAISE EXCEPTION 'Account % does not accept a project', a.account_no;
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER journal_lines_validate
    BEFORE INSERT OR UPDATE OR DELETE ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION journal_lines_validate();
```

`allow_manual_posting` is enforced in Laravel rather than the database. Only the
application knows whether a line comes from a manual journal voucher or a
system document (section 12.7).

#### 12.3.8 Budgets

```sql
CREATE TABLE account_budgets (
    id                bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    company_id        bigint        NOT NULL REFERENCES companies(id),
    account_id        bigint        NOT NULL REFERENCES accounts(id),
    fiscal_period_id  bigint        NOT NULL REFERENCES fiscal_periods(id),
    cost_center_id    bigint        REFERENCES cost_centers(id),
    project_id        bigint        REFERENCES projects(id),
    amount            numeric(18,4) NOT NULL,
    created_at        timestamptz,
    updated_at        timestamptz,
    CONSTRAINT account_budgets_unique
        UNIQUE NULLS NOT DISTINCT (account_id, fiscal_period_id, cost_center_id, project_id)
);
```

For an audit trail (who changed what, with before/after values), use a package
such as `owen-it/laravel-auditing` on `Account`, `Party`, `PartyCustomer`,
`PartySupplier`, `TaxCode` and `SystemAccount`, or a custom `audit_logs` table
with `old_values jsonb` / `new_values jsonb`.

### 12.4 Seed data

Run the seeders in this order:
1. `CurrencySeeder`
2. `CompanySeeder`
3. `AccountSubtypeSeeder`
4. `ChartOfAccountsSeeder`
5. `SystemAccountSeeder`
6. `TaxCodeSeeder`
7. `PartyGroupSeeder`
8. `NumberSeriesSeeder`
9. `FiscalYearSeeder`

#### 12.4.1 Account subtypes

| code | class | contra | cash flow |
|------|-------|:------:|-----------|
| `cash` | asset | | cash |
| `bank` | asset | | cash |
| `receivable` | asset | | operating |
| `allowance_doubtful` | asset | ✔ | operating |
| `other_receivable` | asset | | operating |
| `prepayment` | asset | | operating |
| `inventory` | asset | | operating |
| `tax_asset` | asset | | operating |
| `fixed_asset` | asset | | investing |
| `accumulated_depreciation` | asset | ✔ | investing |
| `right_of_use_asset` | asset | | investing |
| `intangible_asset` | asset | | investing |
| `other_asset` | asset | | investing |
| `payable` | liability | | operating |
| `accrual` | liability | | operating |
| `customer_advance` | liability | | operating |
| `tax_liability` | liability | | operating |
| `employee_payable` | liability | | operating |
| `provision` | liability | | operating |
| `borrowing` | liability | | financing |
| `lease_liability` | liability | | financing |
| `deferred_tax` | liability | | operating |
| `share_capital` | equity | | financing |
| `reserve` | equity | | financing |
| `retained_earnings` | equity | | — |
| `current_year_pl` | equity | | — |
| `drawings` | equity | ✔ | financing |
| `opening_balance_equity` | equity | | — |
| `revenue` | income | | operating |
| `contra_revenue` | income | ✔ | operating |
| `other_income` | income | | operating |
| `cogs` | expense | | operating |
| `operating_expense` | expense | | operating |
| `depreciation` | expense | | operating |
| `finance_cost` | expense | | operating |
| `tax_expense` | expense | | operating |
| `other_expense` | expense | | operating |
| `suspense` | asset | | — |

#### 12.4.2 Starter chart of accounts

Seed the full chart from section 11.6. Follow these rules:

- **A child's `account_no` starts with its parent's `account_no`**
  (`1` → `11` → `1101` → `110101`). That way `ORDER BY account_no` always shows
  the tree in the right order. Validate this in Laravel when an account is
  created.
- Only roots need an `account_class`. Children inherit it, except P&L accounts
  that override it (e.g. `7101 Other Income` = `income` under head `7`).
- `is_group` becomes `true` automatically when the first child is created. To
  seed a group that has no children yet, pass `'is_group' => true`.

```php
<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = Company::where('code', '01')->value('id');
        $subtypes  = AccountSubtype::pluck('id', 'code');

        // [account_no, name, parent_no, class (roots / P&L overrides only), subtype, extra attributes]
        $rows = [
            ['1',      'ASSETS',                        null,   'asset',     null],
            ['11',     'Current Assets',                '1',    null,        null],
            ['1101',   'Cash & Cash Equivalents',       '11',   null,        null],
            ['110101', 'Cash on Hand',                  '1101', null,        'cash'],
            ['110102', 'Petty Cash',                    '1101', null,        'cash'],
            ['1102',   'Trade Receivables',             '11',   null,        null],
            ['110201', 'Trade Receivables - Customers', '1102', null,        'receivable',
                ['is_control' => true, 'control_type' => 'customer', 'allow_manual_posting' => false]],
            ['110202', 'Allowance for Doubtful Debts',  '1102', null,        'allowance_doubtful', ['is_contra' => true]],
            ['110203', 'PDC Received',                  '1102', null,        'receivable'],
            // ... remaining asset accounts from section 11.6 ...
            ['2',      'LIABILITIES',                   null,   'liability', null],
            ['21',     'Current Liabilities',           '2',    null,        null],
            ['2101',   'Trade Payables',                '21',   null,        null],
            ['210101', 'Trade Payables - Suppliers',    '2101', null,        'payable',
                ['is_control' => true, 'control_type' => 'supplier', 'allow_manual_posting' => false]],
            // ... remaining liability accounts ...
            ['3',      'EQUITY',                        null,   'equity',    null],
            ['3104',   'Current Year Profit / Loss',    '3',    null,        'current_year_pl', ['allow_manual_posting' => false]],
            // ... remaining equity, 4 REVENUE (income), 5 COST OF SALES (expense), 6 OPERATING EXPENSES (expense) ...
            ['7',      'OTHER INCOME / EXPENSE & TAX',  null,   'expense',   null],
            ['7101',   'Other Income',                  '7',    'income',    'other_income'],
            // ...
            ['9',      'SYSTEM',                        null,   'asset',     null],
            ['9101',   'Suspense Account',              '9',    null,        'suspense'],
        ];

        DB::transaction(function () use ($rows, $companyId, $subtypes) {
            $ids = [];
            foreach ($rows as $row) {
                [$no, $name, $parentNo, $class, $subtype] = $row;

                $account = Account::create(array_merge([
                    'company_id'         => $companyId,
                    'parent_id'          => $parentNo ? $ids[$parentNo] : null,
                    'account_no'         => $no,
                    'name'               => $name,
                    'account_class'      => $class,
                    'account_subtype_id' => $subtype ? $subtypes[$subtype] : null,
                    'status'             => 'active',
                ], $row[5] ?? []));

                $ids[$no] = $account->id;
            }
        });
    }
}
```

#### 12.4.3 System accounts (replaces `control_table` account columns)

| key | account_no | Used by |
|-----|------------|---------|
| `cash_default` | 110101 | Cash sales / cash purchases |
| `ar_default` | 110201 | Default for new customer groups |
| `ap_default` | 210101 | Default for new supplier groups |
| `pdc_received` | 110203 | Post-dated cheques received |
| `pdc_issued` | 210102 | Post-dated cheques issued |
| `supplier_advance` | 110302 | Advance payments to suppliers |
| `customer_advance` | 210202 | Advances received from customers |
| `inventory` | 110401 | Stock (perpetual) |
| `goods_in_transit` | 110402 | Imports in transit |
| `grni` | 210203 | GRN posted before the supplier invoice |
| `vat_input` | 110501 | Input VAT |
| `vat_output` | 210301 | Output VAT |
| `vat_payable` | 210302 | VAT return settlement |
| `vat_rcm` | 210303 | Reverse charge VAT |
| `corporate_tax_payable` | 210304 | Corporate tax provision |
| `retained_earnings` | 3103 | Year-end closing |
| `current_year_pl` | 3104 | Year-end closing / balance sheet |
| `opening_balance_equity` | 3199 | Opening balance journal |
| `sales_revenue` | 4101 | Default sales account |
| `service_revenue` | 4102 | Default service account |
| `sales_return` | 4103 | Sales returns |
| `sales_discount` | 4104 | Invoice discounts |
| `cogs` | 5101 | Cost of goods sold |
| `freight_in` | 5102 | Landed cost (freight) |
| `customs_duty` | 5103 | Landed cost (duty) |
| `inventory_adjustment` | 5104 | Stock adjustments / write-offs |
| `bank_charges` | 6601 | Bank charges on receipts / payments |
| `fx_realised` | 7102 | Realised exchange difference |
| `fx_unrealised` | 7103 | Period-end revaluation |
| `fa_disposal` | 7104 | Fixed-asset disposal |
| `rounding` | 7105 | Rounding differences |
| `suspense` | 9101 | Unidentified items |

Item groups, banks, expense types, etc. can override these with their own
`*_account_id` columns. The `system_accounts` value is the fallback.

#### 12.4.4 Tax codes (UAE VAT)

| code | category | rate | applies_to | output account | input account | VAT201 box (sales / purchase) |
|------|----------|-----:|------------|----------------|---------------|-------------------------------|
| `SR` | standard | 5 | both | 210301 VAT Output | 110501 VAT Input | 1 / 9 |
| `ZR` | zero | 0 | both | — | — | 4 / — |
| `EX` | exempt | 0 | both | — | — | 5 / — |
| `OOS` | out_of_scope | 0 | both | — | — | — / — |
| `RCM` | reverse_charge | 5 | purchase | 210303 Reverse Charge VAT | 110501 VAT Input | 3 / 10 |

Check the box numbers against the current FTA VAT201 form with your tax advisor.
Box 1 is split by emirate, so store the emirate on the branch or invoice.

#### 12.4.5 Party groups and number series

| Seeder | Rows |
|--------|------|
| `PartyGroupSeeder` | `LOCAL-CUST` (customer → 110201, default), `LOCAL-SUPP` (supplier → 210101, default). Add `FOREIGN-CUST`, `FOREIGN-SUPP`, `RELATED` as needed. |
| `NumberSeriesSeeder` | `PARTY` prefix `P`, padding 6. `JV`, `SI`, `SR`, `PI`, `PR`, `RV`, `PV`, `CN`, `DN`, `OB`, `YC`, `REV` per fiscal year, e.g. prefix `JV27-`. |

### 12.5 Report queries (PostgreSQL)

All reports read from `journal_lines` joined to `journals` with
`status IN ('posted','reversed')`. A reversed journal and its reversal both
count, and they cancel each other out. Draft journals are never included.

Laravel's PDO accepts named bindings (`DB::select($sql, ['company' => 1, ...])`).
Use `CAST(:x AS date)` rather than `:x::date`.

#### Trial balance (rolled up through the tree)

```sql
WITH bal AS (
    SELECT jl.account_id,
           SUM(CASE WHEN jl.journal_date <  CAST(:from AS date) THEN jl.debit - jl.credit ELSE 0 END) AS opening,
           SUM(CASE WHEN jl.journal_date >= CAST(:from AS date) THEN jl.debit  ELSE 0 END)           AS period_dr,
           SUM(CASE WHEN jl.journal_date >= CAST(:from AS date) THEN jl.credit ELSE 0 END)           AS period_cr
    FROM journal_lines jl
    JOIN journals j ON j.id = jl.journal_id AND j.status IN ('posted','reversed')
    WHERE jl.company_id = :company
      AND jl.journal_date <= CAST(:to AS date)
    GROUP BY jl.account_id
)
SELECT g.account_no, g.name, g.level, g.is_group, g.normal_balance, g.statement_type,
       COALESCE(SUM(b.opening), 0)                              AS opening,     -- + = debit
       COALESCE(SUM(b.period_dr), 0)                            AS period_dr,
       COALESCE(SUM(b.period_cr), 0)                            AS period_cr,
       COALESCE(SUM(b.opening + b.period_dr - b.period_cr), 0)  AS closing      -- + = debit
FROM accounts g
JOIN accounts a  ON a.company_id = g.company_id AND a.path LIKE g.path || '%'   -- itself + descendants
LEFT JOIN bal b  ON b.account_id = a.id
WHERE g.company_id = :company
GROUP BY g.id, g.account_no, g.name, g.level, g.is_group, g.normal_balance, g.statement_type
ORDER BY g.account_no;
```

P&L balances start at zero each year because the year-end closing journal
(`YC`) transfers them to `3103 Retained Earnings`. Until a year has been
closed, show `3104 Current Year Profit / Loss` as the calculated P&L total.

#### Party ledger / statement (one customer or supplier)

```sql
WITH lines AS (
    SELECT jl.id, jl.journal_date, j.source_type, j.journal_no, j.reference,
           COALESCE(jl.description, j.narration) AS description, jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journals j ON j.id = jl.journal_id AND j.status IN ('posted','reversed')
    WHERE jl.company_id = :company
      AND jl.party_id   = :party
      AND jl.account_id = :control_account          -- party_customers.ar_account_id or party_suppliers.ap_account_id
      AND jl.journal_date <= CAST(:to AS date)
)
SELECT NULL AS journal_date, 'OPENING' AS source_type, NULL AS journal_no, NULL AS reference,
       'Opening balance' AS description, NULL AS debit, NULL AS credit,
       COALESCE(SUM(debit - credit), 0) AS running_balance, 0 AS sort_key, 0 AS id
FROM lines WHERE journal_date < CAST(:from AS date)
UNION ALL
SELECT journal_date, source_type, journal_no, reference, description, debit, credit,
       (SELECT COALESCE(SUM(debit - credit), 0) FROM lines WHERE journal_date < CAST(:from AS date))
       + SUM(debit - credit) OVER (ORDER BY journal_date, id) AS running_balance,
       1 AS sort_key, id
FROM lines WHERE journal_date >= CAST(:from AS date)
ORDER BY sort_key, journal_date NULLS FIRST, id;
```

For suppliers, display `-running_balance` so the amount owed shows as positive.

#### Open items: outstanding invoices and unallocated payments

```sql
WITH open_items AS (
    -- Invoices (debits) less what has been allocated to them
    SELECT i.id, i.party_id, j.journal_no, i.journal_date,
           COALESCE(i.due_date, i.journal_date) AS due_date,
           i.debit AS amount,
           i.debit - COALESCE((SELECT SUM(a.amount) FROM allocations a
                                WHERE a.invoice_line_id = i.id
                                  AND a.allocated_on <= CAST(:as_of AS date)), 0) AS outstanding
    FROM journal_lines i
    JOIN journals j ON j.id = i.journal_id AND j.status IN ('posted','reversed')
    WHERE i.company_id = :company AND i.account_id = :ar_account
      AND i.debit > 0 AND i.journal_date <= CAST(:as_of AS date)
    UNION ALL
    -- Receipts / credit notes (credits) not yet allocated: shown as negative "on account"
    SELECT p.id, p.party_id, j.journal_no, p.journal_date, p.journal_date,
           -p.credit,
           -(p.credit - COALESCE((SELECT SUM(a.amount) FROM allocations a
                                   WHERE a.payment_line_id = p.id
                                     AND a.allocated_on <= CAST(:as_of AS date)), 0))
    FROM journal_lines p
    JOIN journals j ON j.id = p.journal_id AND j.status IN ('posted','reversed')
    WHERE p.company_id = :company AND p.account_id = :ar_account
      AND p.credit > 0 AND p.journal_date <= CAST(:as_of AS date)
)
SELECT * FROM open_items
WHERE outstanding <> 0
  AND (CAST(:party AS bigint) IS NULL OR party_id = CAST(:party AS bigint))
ORDER BY party_id, due_date;
```

The total of all open items for a party equals that party's balance on the
control account. That's the month-end reconciliation check.

#### Aging (summary by party)

```sql
-- Reuse the open_items CTE above, then:
SELECT p.party_code, p.party_name,
       SUM(o.outstanding) FILTER (WHERE CAST(:as_of AS date) - o.due_date <= 0)             AS not_due,
       SUM(o.outstanding) FILTER (WHERE CAST(:as_of AS date) - o.due_date BETWEEN 1  AND 30) AS days_1_30,
       SUM(o.outstanding) FILTER (WHERE CAST(:as_of AS date) - o.due_date BETWEEN 31 AND 60) AS days_31_60,
       SUM(o.outstanding) FILTER (WHERE CAST(:as_of AS date) - o.due_date BETWEEN 61 AND 90) AS days_61_90,
       SUM(o.outstanding) FILTER (WHERE CAST(:as_of AS date) - o.due_date > 90)              AS over_90,
       SUM(o.outstanding)                                                                   AS total
FROM open_items o
JOIN parties p ON p.id = o.party_id
WHERE o.outstanding <> 0
GROUP BY p.id, p.party_code, p.party_name
ORDER BY p.party_name;
```

In PostgreSQL, `date - date` returns a whole number of days.

#### Credit-limit check before saving a sales invoice

```sql
SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
FROM journal_lines jl
JOIN journals j ON j.id = jl.journal_id AND j.status IN ('posted','reversed')
WHERE jl.company_id = :company AND jl.party_id = :party AND jl.account_id = :ar_account;
-- Block (or ask for approval) when balance + new invoice total > party_customers.credit_limit
```

#### Group-wise ledger report (replaces the old "Accounts Ledger – Group" screen)

In the old ERP, the user picks a **Group Header** in "Accounts Ledger – Group":
- Picking `0` (the technical root) prints every ledger.
- Picking a group such as `00010 CURRENT ASSETS` prints every ledger under it.

Because customers and suppliers are ledgers in the old ERP, the same report also
prints a ledger per customer, e.g. `1275-AL DANA`, `1390-GARHOUD`.

The new application has no root `0`. The four (or more) heads are true roots
with `parent_id = NULL`. The same results come from two reports:

| Old ERP selection | New application |
|-------------------|-----------------|
| Group Header = `0` (all ledgers) | **GL Ledger – Group-wise** with Group = *All accounts* (empty) |
| Group Header = any GL group (e.g. `00010 CURRENT ASSETS`) | **GL Ledger – Group-wise** with that group: every ledger whose `path` starts with the group's `path` |
| Group Header = `00070 SUNDRY DEBTORS` / `00090 SUNDRY CREDITORS` (a ledger per customer/supplier) | **Party Ledger – Group-wise** with Role = Customer/Supplier and Party group = *All* or a specific group: one section per party |
| "All Branch" tick box | Branch filter empty = all branches; otherwise `journal_lines.branch_id` |
| "Remove Zero Balance" | Skip ledgers/parties whose opening and closing balances are both 0 and that have no movement |
| Format 01 (detailed with opening) | Opening line + transactions + running balance + total per ledger |
| Format 02 (summary) | One line per ledger: opening, total debit, total credit, closing |
| Format 03 (without opening) | Transactions only; running balance starts from 0 |

In the GL report, a control account such as `110201 Trade Receivables` appears
as one ledger. Offer an **"Expand control accounts by party"** option: when it's
ticked, Laravel replaces that ledger's section with the party sections from the
party query below. The printout then looks like the old ERP.

**GL Ledger – Group-wise (Format 01).** `:group_id` NULL = all accounts,
`:branch` NULL = all branches.

```sql
WITH selected AS (
    SELECT a.id, a.account_no, a.name, a.normal_balance
    FROM accounts a
    WHERE a.company_id = :company
      AND a.is_group = false
      AND (CAST(:group_id AS bigint) IS NULL
           OR a.path LIKE (SELECT g.path FROM accounts g WHERE g.id = CAST(:group_id AS bigint)) || '%')
),
lines AS (
    SELECT jl.account_id, jl.id, jl.journal_date, j.source_type, j.journal_no,
           COALESCE(jl.description, j.narration) AS description, jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journals j ON j.id = jl.journal_id AND j.status IN ('posted','reversed')
    JOIN selected s ON s.id = jl.account_id
    WHERE jl.company_id = :company
      AND jl.journal_date <= CAST(:to AS date)
      AND (CAST(:branch AS bigint) IS NULL OR jl.branch_id = CAST(:branch AS bigint))
),
opening AS (
    SELECT account_id, SUM(debit - credit) AS balance
    FROM lines
    WHERE journal_date < CAST(:from AS date)
    GROUP BY account_id
)
SELECT s.account_no, s.name,
       COALESCE(o.balance, 0) AS opening_balance,
       l.journal_date, l.source_type, l.journal_no, l.description, l.debit, l.credit,
       COALESCE(o.balance, 0)
         + SUM(COALESCE(l.debit, 0) - COALESCE(l.credit, 0))
           OVER (PARTITION BY s.id ORDER BY l.journal_date, l.id) AS running_balance
FROM selected s
LEFT JOIN opening o ON o.account_id = s.id
LEFT JOIN lines l   ON l.account_id = s.id AND l.journal_date >= CAST(:from AS date)
ORDER BY s.account_no, l.journal_date NULLS FIRST, l.id;
```

A ledger with no movement in the period returns one row with only the opening
balance. "Remove Zero Balance" drops it when that balance is 0. Format 02 groups
the same data by account. Format 03 drops the opening balance from the
running-balance expression.

**Party Ledger – Group-wise.** One section per customer or supplier, like
`1275-AL DANA` in the old printout. `:party_group_id` NULL = all groups.

```sql
WITH selected AS (
    SELECT p.id AS party_id, p.party_code, p.party_name, pc.ar_account_id AS control_account_id
    FROM parties p
    JOIN party_customers pc ON pc.party_id = p.id          -- use party_suppliers / ap_account_id for suppliers
    WHERE p.company_id = :company
      AND (CAST(:party_group_id AS bigint) IS NULL OR pc.party_group_id = CAST(:party_group_id AS bigint))
),
lines AS (
    SELECT jl.party_id, jl.id, jl.journal_date, j.source_type, j.journal_no,
           COALESCE(jl.description, j.narration) AS description, jl.debit, jl.credit
    FROM journal_lines jl
    JOIN journals j ON j.id = jl.journal_id AND j.status IN ('posted','reversed')
    JOIN selected s ON s.party_id = jl.party_id AND s.control_account_id = jl.account_id
    WHERE jl.company_id = :company
      AND jl.journal_date <= CAST(:to AS date)
      AND (CAST(:branch AS bigint) IS NULL OR jl.branch_id = CAST(:branch AS bigint))
),
opening AS (
    SELECT party_id, SUM(debit - credit) AS balance
    FROM lines
    WHERE journal_date < CAST(:from AS date)
    GROUP BY party_id
)
SELECT s.party_code, s.party_name,
       COALESCE(o.balance, 0) AS opening_balance,
       l.journal_date, l.source_type, l.journal_no, l.description, l.debit, l.credit,
       COALESCE(o.balance, 0)
         + SUM(COALESCE(l.debit, 0) - COALESCE(l.credit, 0))
           OVER (PARTITION BY s.party_id ORDER BY l.journal_date, l.id) AS running_balance
FROM selected s
LEFT JOIN opening o ON o.party_id = s.party_id
LEFT JOIN lines l   ON l.party_id = s.party_id AND l.journal_date >= CAST(:from AS date)
ORDER BY s.party_code, l.journal_date NULLS FIRST, l.id;
```

On the selection screen, replace the old "Group Header" search with:
- an account-group picker whose first option is **All accounts**, listing the
  heads and groups (`is_group = true`) in `account_no` order;
- a **Role** (Customer / Supplier) and **Party group** filter for the party
  version.

### 12.6 Laravel implementation

#### 12.6.1 Folder structure

```
app/
├── Enums/Accounting/
│   ├── AccountClass.php          # asset, liability, equity, income, expense
│   ├── AccountStatus.php         # pending, active, inactive
│   ├── JournalStatus.php         # draft, posted, reversed
│   ├── SourceType.php            # JV, OB, SI, SR, PI, PR, RV, PV, CN, DN, FXR, YC, REV
│   └── DimensionRule.php         # none, optional, required
├── Models/
│   ├── Concerns/BelongsToCompany.php
│   ├── Company.php, Branch.php, Currency.php, ExchangeRate.php
│   ├── Accounting/
│   │   ├── Account.php, AccountSubtype.php, SystemAccount.php
│   │   ├── FiscalYear.php, FiscalPeriod.php
│   │   ├── Journal.php, JournalLine.php, Allocation.php
│   │   ├── TaxCode.php, CostCenter.php, Project.php, AccountBudget.php
│   │   └── NumberSeries.php
│   └── Party/
│       ├── Party.php, PartyGroup.php, PartyAddress.php, PartyContact.php
│       ├── PartyBankAccount.php, PartyCustomer.php, PartySupplier.php
├── Services/Accounting/
│   ├── AccountService.php        # create / move / activate / deactivate accounts
│   ├── PostingService.php        # the ONLY way to write journals
│   ├── AllocationService.php     # match receipts/payments to invoices
│   ├── NumberSeriesService.php   # next document / party number (row lock)
│   ├── SystemAccountResolver.php # get('vat_output', $branchId)
│   ├── PeriodService.php         # open / close / lock periods, year-end closing
│   └── Reports/
│       ├── TrialBalanceReport.php
│       ├── PartyLedgerReport.php
│       └── AgingReport.php
├── Services/Party/PartyService.php   # create customer / supplier with roles
├── Http/
│   ├── Controllers/Accounting/{AccountController, JournalController, ReportController}.php
│   ├── Controllers/Party/{CustomerController, SupplierController}.php
│   └── Requests/Accounting/{StoreAccountRequest, StoreJournalRequest}.php ...
└── Policies/{AccountPolicy, JournalPolicy, PartyPolicy}.php

database/
├── migrations/      # see 12.6.2
├── seeders/         # see 12.4
└── sql/             # trigger SQL files loaded by migrations
    ├── accounts_triggers.sql
    └── journal_triggers.sql
```

#### 12.6.2 Migration order

Laravel's default `users` migration runs first.

| # | Migration | Tables |
|---|-----------|--------|
| 1 | `create_currencies_table` | `currencies` |
| 2 | `create_companies_table` | `companies`, `exchange_rates` |
| 3 | `create_branches_table` | `branches` |
| 4 | `create_account_subtypes_table` | `account_subtypes` |
| 5 | `create_accounts_table` | `accounts` + `accounts_triggers.sql` |
| 6 | `create_system_accounts_table` | `system_accounts` |
| 7 | `create_fiscal_tables` | `fiscal_years`, `fiscal_periods` |
| 8 | `create_cost_centers_table` | `cost_centers` |
| 9 | `create_tax_codes_table` | `tax_codes` |
| 10 | `create_party_tables` | `party_groups`, `parties`, `party_addresses`, `party_contacts`, `party_bank_accounts`, `party_customers`, `party_suppliers` |
| 11 | `create_projects_table` | `projects` |
| 12 | `create_number_series_table` | `number_series` |
| 13 | `create_journal_tables` | `journals`, `journal_lines` + `journal_triggers.sql` |
| 14 | `create_allocations_table` | `allocations` |
| 15 | `create_account_budgets_table` | `account_budgets` |

Example: the `accounts` migration.

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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('accounts');
            $table->string('account_no', 20);
            $table->string('name', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('account_class', 10);
            $table->char('statement_type', 2);
            $table->char('normal_balance', 1);
            $table->foreignId('account_subtype_id')->nullable()->constrained();
            $table->boolean('is_contra')->default(false);
            $table->string('cash_flow_category', 10)->nullable();
            $table->string('ct_treatment', 15)->default('deductible');
            $table->boolean('is_group')->default(false);
            $table->smallInteger('level')->default(0);
            $table->string('path', 900)->default('');
            $table->boolean('is_control')->default(false);
            $table->string('control_type', 10)->nullable();
            $table->boolean('allow_manual_posting')->default(true);
            $table->string('cost_center_rule', 10)->default('optional');
            $table->string('project_rule', 10)->default('optional');
            $table->char('currency_code', 3)->nullable();
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->string('status', 10)->default('pending');
            $table->boolean('is_blocked')->default(false);
            $table->boolean('is_confidential')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->string('legacy_ac_code', 10)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->unique(['company_id', 'account_no']);
            $table->index('parent_id');
        });

        DB::statement("ALTER TABLE accounts
            ADD CONSTRAINT accounts_class_chk   CHECK (account_class IN ('asset','liability','equity','income','expense')),
            ADD CONSTRAINT accounts_stmt_chk    CHECK (statement_type IN ('BS','PL')),
            ADD CONSTRAINT accounts_normal_chk  CHECK (normal_balance IN ('D','C')),
            ADD CONSTRAINT accounts_status_chk  CHECK (status IN ('pending','active','inactive')),
            ADD CONSTRAINT accounts_cc_rule_chk CHECK (cost_center_rule IN ('none','optional','required')),
            ADD CONSTRAINT accounts_pj_rule_chk CHECK (project_rule IN ('none','optional','required')),
            ADD CONSTRAINT accounts_ct_chk      CHECK (ct_treatment IN ('deductible','partial_50','non_deductible','exempt')),
            ADD CONSTRAINT accounts_cf_chk      CHECK (cash_flow_category IN ('cash','operating','investing','financing')),
            ADD CONSTRAINT accounts_control_type_chk  CHECK (is_control = false OR control_type IS NOT NULL),
            ADD CONSTRAINT accounts_group_control_chk CHECK (NOT (is_group AND is_control))");

        DB::statement('CREATE INDEX accounts_path_idx ON accounts (company_id, path varchar_pattern_ops)');

        DB::unprepared(file_get_contents(database_path('sql/accounts_triggers.sql')));
    }

    public function down(): void
    {
        DB::unprepared('
            DROP TRIGGER IF EXISTS accounts_after_write ON accounts;
            DROP TRIGGER IF EXISTS accounts_before_write ON accounts;
            DROP FUNCTION IF EXISTS accounts_after_write();
            DROP FUNCTION IF EXISTS accounts_before_write();
        ');
        Schema::dropIfExists('accounts');
    }
};
```

For `UNIQUE NULLS NOT DISTINCT`, which the schema builder doesn't support, add
it with `DB::statement('ALTER TABLE system_accounts ADD CONSTRAINT ... UNIQUE NULLS NOT DISTINCT (...)')`.

#### 12.6.3 Company scope

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            if ($companyId = session('company_id')) {
                $query->where($query->getModel()->getTable() . '.company_id', $companyId);
            }
        });

        static::creating(function ($model) {
            $model->company_id ??= session('company_id');
        });
    }
}
```

#### 12.6.4 `Account` model

```php
<?php

namespace App\Models\Accounting;

use App\Enums\Accounting\AccountClass;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'parent_id', 'account_no', 'name', 'name_ar', 'account_class',
        'account_subtype_id', 'is_contra', 'cash_flow_category', 'ct_treatment',
        'is_control', 'control_type', 'allow_manual_posting', 'cost_center_rule',
        'project_rule', 'currency_code', 'status', 'is_blocked', 'is_confidential',
        'is_group', 'legacy_ac_code',
    ];

    // path, level, statement_type and normal_balance are maintained by database triggers

    protected function casts(): array
    {
        return [
            'account_class'        => AccountClass::class,
            'is_group'             => 'boolean',
            'is_contra'            => 'boolean',
            'is_control'           => 'boolean',
            'allow_manual_posting' => 'boolean',
            'is_blocked'           => 'boolean',
            'is_confidential'      => 'boolean',
            'approved_at'          => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('account_no');
    }

    public function subtype(): BelongsTo
    {
        return $this->belongsTo(AccountSubtype::class, 'account_subtype_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function scopeDescendantsOf(Builder $query, Account $account): Builder
    {
        return $query->where('company_id', $account->company_id)
            ->where('path', 'like', $account->path . '%')
            ->whereKeyNot($account->id);
    }

    public function scopeAncestorsOf(Builder $query, Account $account): Builder
    {
        $ids = array_filter(explode('/', trim($account->path, '/')));
        return $query->whereIn('id', $ids)->whereKeyNot($account->id)->orderBy('level');
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_group', false)
            ->where('status', 'active')
            ->where('is_blocked', false);
    }
}
```

After `Account::create(...)`, call `$account->refresh()` to load the values the
trigger set (`path`, `level`, `statement_type`, `normal_balance`).

#### 12.6.5 `AccountService`: creating an account

```php
public function create(array $data, User $user): Account
{
    return DB::transaction(function () use ($data, $user) {
        $parent = isset($data['parent_id'])
            ? Account::lockForUpdate()->findOrFail($data['parent_id'])
            : null;

        if ($parent && ! str_starts_with($data['account_no'], $parent->account_no)) {
            throw ValidationException::withMessages([
                'account_no' => "Account number must start with the parent number {$parent->account_no}.",
            ]);
        }
        if ($parent?->is_control) {
            throw ValidationException::withMessages([
                'parent_id' => 'Accounts cannot be created under a control account. Create a party instead.',
            ]);
        }

        $account = Account::create($data + [
            'status'     => $user->can('approve', Account::class) ? 'active' : 'pending',
            'created_by' => $user->id,
        ]);

        return $account->refresh();
    });
}

public function suggestNextNumber(Account $parent): string
{
    $last = Account::where('parent_id', $parent->id)->max('account_no');

    if (! $last) {
        $step = $parent->level === 0 ? 1 : 2;
        return $parent->account_no . str_pad('1', $step, '0', STR_PAD_LEFT);
    }

    $next = (string) ((int) $last + 1);
    if (! str_starts_with($next, $parent->account_no)) {
        throw ValidationException::withMessages([
            'account_no' => "The number range under {$parent->account_no} is full.",
        ]);
    }

    return $next;
}
```

The second function suggests the next free number in the parent's range:
- If the parent already has children, it adds 1 to the highest sibling. Under
  `1101` with `110110` as the highest child it suggests `110111`; under `3`
  with `3105` it suggests `3106`.
- If the parent has no children yet, it adds `1` after a root number (`1` →
  `11`) or `01` after any other number (`1101` → `110101`).

#### 12.6.6 `PostingService`: the only way to write journals

Every module (sales invoice, receipt, purchase, payroll, …) builds its lines and
calls this service. No module inserts into `journals` or `journal_lines`
directly.

```php
<?php

namespace App\Services\Accounting;

use App\Enums\Accounting\JournalStatus;
use App\Enums\Accounting\SourceType;
use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalPeriod;
use App\Models\Accounting\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostingService
{
    public function __construct(private NumberSeriesService $numbers) {}

    /**
     * @param  array<int, array{account_id:int, debit?:string, credit?:string, party_id?:int,
     *                          cost_center_id?:int, project_id?:int, tax_code_id?:int,
     *                          description?:string, due_date?:string}>  $lines
     */
    public function post(
        int $companyId,
        string $date,
        SourceType $source,
        ?int $sourceId,
        array $lines,
        array $header = [],
    ): Journal {
        if ($source === SourceType::Manual) {
            $this->assertManualPostingAllowed($lines);
        }

        return DB::transaction(function () use ($companyId, $date, $source, $sourceId, $lines, $header) {
            $period = FiscalPeriod::where('company_id', $companyId)
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->where('period_no', '<=', 12)
                ->firstOrFail();

            $journal = Journal::create($header + [
                'company_id'       => $companyId,
                'fiscal_period_id' => $period->id,
                'journal_no'       => $this->numbers->next($companyId, $source->value, $period->fiscal_year_id),
                'journal_date'     => $date,
                'source_type'      => $source->value,
                'source_id'        => $sourceId,
                'currency_code'    => $header['currency_code'] ?? 'AED',
                'status'           => JournalStatus::Draft,
                'created_by'       => auth()->id(),
            ]);

            foreach (array_values($lines) as $i => $line) {
                $journal->lines()->create($line + [
                    'company_id'   => $companyId,
                    'line_no'      => $i + 1,
                    'journal_date' => $date,
                    'debit'        => '0',
                    'credit'       => '0',
                ]);
            }

            // The journals_validate trigger checks balance, period and dates here
            $journal->update(['status' => JournalStatus::Posted, 'posted_by' => auth()->id()]);

            return $journal->refresh();
        });
    }

    public function reverse(Journal $original, string $date, string $reason): Journal
    {
        return DB::transaction(function () use ($original, $date, $reason) {
            $lines = $original->lines->map(fn ($l) => [
                'account_id'     => $l->account_id,
                'party_id'       => $l->party_id,
                'cost_center_id' => $l->cost_center_id,
                'project_id'     => $l->project_id,
                'tax_code_id'    => $l->tax_code_id,
                'description'    => $l->description,
                'debit'          => $l->credit,   // swapped
                'credit'         => $l->debit,
            ])->all();

            $reversal = $this->post($original->company_id, $date, SourceType::Reversal, $original->id, $lines, [
                'reversal_of_id' => $original->id,
                'narration'      => "Reversal of {$original->journal_no}: {$reason}",
                'currency_code'  => $original->currency_code,
                'exchange_rate'  => $original->exchange_rate,
            ]);

            $original->update(['status' => JournalStatus::Reversed]);

            return $reversal;
        });
    }

    private function assertManualPostingAllowed(array $lines): void
    {
        $blocked = Account::whereIn('id', array_column($lines, 'account_id'))
            ->where('allow_manual_posting', false)
            ->pluck('account_no');

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Manual journals cannot post to: ' . $blocked->implode(', '),
            ]);
        }
    }
}
```

Example lines a sales invoice builds for AED 1,000 + 5% VAT:

```php
$ar      = $customer->ar_account_id;                          // party_customers
$revenue = $resolver->get('sales_revenue', $branchId);        // or the item group's sales account
$vatOut  = $taxCode->output_account_id;                       // tax_codes SR → 210301

$posting->post($companyId, $invoice->date, SourceType::SalesInvoice, $invoice->id, [
    ['account_id' => $ar,      'party_id' => $customer->party_id, 'debit'  => '1050.0000',
     'due_date'   => $invoice->date->addDays($customer->credit_days ?? 0)->toDateString()],
    ['account_id' => $revenue, 'credit' => '1000.0000', 'tax_code_id' => $taxCode->id],
    ['account_id' => $vatOut,  'credit' => '50.0000',   'tax_code_id' => $taxCode->id],
]);
```

#### 12.6.7 `NumberSeriesService`

```php
public function next(int $companyId, string $docType, ?int $fiscalYearId = null, ?int $branchId = null): string
{
    return DB::transaction(function () use ($companyId, $docType, $fiscalYearId, $branchId) {
        $series = NumberSeries::where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $series->prefix . str_pad((string) $series->next_number, $series->padding, '0', STR_PAD_LEFT);
        $series->increment('next_number');

        return $number;
    });
}
```

`lockForUpdate()` makes two users saving at the same moment wait for each other
instead of getting the same number.

#### 12.6.8 `AllocationService`: matching receipts to invoices

When a receipt is saved, the user ticks the invoices it pays. For each one:

1. Lock both journal lines (`lockForUpdate`).
2. Check that both lines have the same `party_id` and `account_id`.
3. Check that the amount isn't more than the invoice's remaining outstanding
   amount, or the payment's remaining unallocated amount.
4. Insert an `allocations` row.

Un-allocating deletes the row and keeps the audit log. It never touches the
journal.

#### 12.6.9 `PartyService`: creating a customer or supplier

```php
public function createCustomer(array $data): Party
{
    return DB::transaction(function () use ($data) {
        $group = PartyGroup::where('role', 'customer')
            ->when($data['party_group_id'] ?? null, fn ($q, $id) => $q->whereKey($id),
                                                   fn ($q) => $q->where('is_default', true))
            ->firstOrFail();

        $party = Party::create([
            'party_code' => $this->numbers->next(session('company_id'), 'PARTY'),
            'party_name' => trim($data['party_name']),
            'trn'        => $data['trn'] ?? null,
            // ... other party fields
        ]);

        $party->addresses()->createMany($data['addresses'] ?? []);
        $party->contacts()->createMany($data['contacts'] ?? []);

        $party->customer()->create([
            'party_group_id' => $group->id,
            'ar_account_id'  => $group->control_account_id,
            'credit_limit'   => $data['credit_limit'] ?? null,
            'credit_days'    => $data['credit_days'] ?? null,
            'salesman_id'    => $data['salesman_id'] ?? null,
        ]);

        return $party;   // no row is added to accounts
    });
}
```

To make an existing customer also a supplier, call `$party->supplier()->create([...])`.
Nothing else changes.

### 12.7 Where each business rule is enforced

| Rule | Database (trigger / constraint) | Laravel |
|------|:-------------------------------:|:-------:|
| Unique account number per company | ✔ | ✔ (form validation message) |
| Path, level, statement type, normal balance kept correct | ✔ | |
| Parent becomes a group when a child is added | ✔ | |
| No child under a ledger that has postings | ✔ | ✔ (friendly message) |
| No accounts under a control account | ✔ (`group_control_chk`) | ✔ |
| Child account number starts with parent number | | ✔ |
| BS child keeps parent class; class locked once used | ✔ | |
| Journal must balance; at least 2 lines | ✔ | ✔ (pre-check before saving) |
| Posting only in an open period | ✔ | ✔ (closed-period permission) |
| Posted journals immutable; corrections by reversal | ✔ | |
| No posting to group / inactive / blocked accounts | ✔ | ✔ (pickers use `postable()`) |
| Party mandatory on control accounts | ✔ | ✔ |
| Cost center / project rules | ✔ | ✔ |
| `allow_manual_posting` | | ✔ (`PostingService`) |
| No over-allocation | | ✔ (`AllocationService` with row locks) |
| Credit limit / blocked customer | | ✔ (sales documents) |
| Approval of new accounts (maker-checker) | | ✔ (policy + `status`) |
| Confidential accounts hidden | | ✔ (policy / global scope) |
| Money as decimal, never float | ✔ (`numeric`) | ✔ (`decimal:4` casts, bcmath) |

### 12.8 Build order

| Phase | Deliverable | Done when |
|-------|-------------|-----------|
| **0. Setup** | Laravel project, PostgreSQL connection, auth, roles/permissions, `currencies`, `companies`, `branches`, company switcher (`session('company_id')`) | A user can log in and select a company |
| **1. Chart of accounts** | `account_subtypes`, `accounts` + triggers, `system_accounts`, seeders (12.4), COA screen (tree view, create, edit, move, activate/deactivate, approval) | The full chart from 11.6 is seeded; the tree displays in `account_no` order; the trigger rules in 12.7 are covered by tests |
| **2. General ledger** | `fiscal_years`, `fiscal_periods`, `number_series`, `journals`, `journal_lines` + triggers, `PostingService`, manual journal voucher screen, reversal, account ledger report, trial balance | A manual JV can be posted and reversed; the trial balance balances |
| **3. Dimensions & tax** | `cost_centers`, `tax_codes` + seed, cost-center/project rules on JV lines | A JV respects the dimension rules; tax codes are selectable |
| **4. Parties** | `party_groups`, `parties` and child tables, `party_customers`, `party_suppliers`, `projects`, customer and supplier screens | Customers and suppliers are created **without** adding accounts |
| **5. Sub-ledger** | `allocations`, `AllocationService`, receipt/payment screens with invoice matching, party ledger, outstanding invoices, aging, credit-limit check | Party aging total = control account balance |
| **6. Documents** | Sales/purchase invoices, returns, credit/debit notes, stock postings (perpetual) calling `PostingService` | Every document creates exactly one balanced journal |
| **7. Closing & statements** | Period close/lock, year-end closing (`YC`), FX revaluation (`FXR`), Balance Sheet, P&L, cash flow, VAT return (by tax code and box), budgets vs. actual | Year-end close moves P&L to retained earnings; VAT return totals agree with the VAT accounts |
| **8. Migration** | Import from `infosblank` (12.10), parallel run, reconciliation | Old and new trial balances and agings agree |

### 12.9 Testing checklist

Write these as Laravel feature tests (`php artisan test`) against a real
PostgreSQL test database. Triggers do not run on SQLite.

- [ ] Creating `110103` under `1101` sets `level`, `path`, `normal_balance = 'D'`, `statement_type = 'BS'`.
- [ ] Creating a child under a ledger with postings fails.
- [ ] Creating a child under a control account fails.
- [ ] Creating a liability child under an asset group fails; `Other Income` under head `7` succeeds.
- [ ] Moving a group rewrites the `path` of all descendants; moving under its own descendant fails.
- [ ] Posting to a group, pending, inactive or blocked account fails.
- [ ] Posting to Trade Receivables without `party_id` fails.
- [ ] An unbalanced journal can't be posted; a balanced one can.
- [ ] A posted journal can't be updated or deleted; reversing it marks it `reversed` and the trial balance returns to its previous figures.
- [ ] Posting into a closed or locked period fails.
- [ ] Two parallel `NumberSeriesService::next()` calls return different numbers.
- [ ] Allocating more than the outstanding amount fails.
- [ ] Party aging total = control account balance (per party and overall).
- [ ] Trial balance: total debit = total credit; group totals = sum of children.

### 12.10 Migrating from `infosblank` (SQL Server) to PostgreSQL

Add a second connection in `config/database.php`, for example `legacy` using the
`sqlsrv` driver. The Laravel server needs the `pdo_sqlsrv` extension for this.
Then write an artisan command (`php artisan legacy:import`) that runs inside one
PostgreSQL transaction per step.

| Old (`infosblank`) | New table.column | Notes |
|--------------------|------------------|-------|
| `ac_master` rows **without** `parent_ac_code` (GL groups/ledgers) | `accounts` | Map each old `ac_code` to a new `account_no` using a hand-made mapping sheet based on section 11.6, and store the old code in `legacy_ac_code`. Apply the fixes from section 9. |
| `ac_master.description` | `accounts.name` / `parties.party_name` | Trim (section 7.1). |
| `ac_master.description_arabic` | `name_ar` / `party_name_ar` | |
| `ac_master.child_exist`, `active`, `blocked`, `confidential` | `is_group` (set by trigger), `status`, `is_blocked`, `is_confidential` | |
| `ac_master.costcenter_required = 'Y'` | `accounts.cost_center_rule = 'required'` | |
| `ac_master.account_type` | `accounts.account_class` | Take it from the head, not the stored value (it is NULL or wrong on some rows). |
| `ac_master` rows **with** `parent_ac_code = '00070'` | `parties` + `party_customers` | `party_group` = `LOCAL-CUST`; `legacy_ac_code` = old `ac_code` |
| `ac_master` rows **with** `parent_ac_code = '00090'` | `parties` + `party_suppliers` | `party_group` = `LOCAL-SUPP` |
| `tax_no` | `parties.trn` | |
| `cr_amount` | `party_customers.credit_limit` | Not `cr_limit` (section 7.1) |
| `cr_days` | `credit_days` | |
| `salesman_code` | `party_customers.salesman_id` | Through the salesman mapping |
| `address`, `street`, `landmark`, `city`, `country`, `po_box` | `party_addresses` (type `billing`) | |
| `contact_name`, `contact_designation`, `contact_no`, `contact_email` | `party_contacts` | |
| `tel_off`, `email`, `www` | `parties.phone`, `email`, `website` | |
| `bank_name`, `bank_account`, `bank_ibanno`, `bank_swiftcode` | `party_bank_accounts` | |
| `parent_control` (C, S) | `party_groups.control_account_id` | |
| `parent_control` (B, I) | Banks stay GL ledgers; income default → `system_accounts.sales_revenue` | |
| `control_table` (`tax1_*`, `ac_net_pl`, `cost_*`) | `system_accounts` / `tax_codes` | |
| `control_tb` | Not needed | `statement_type` is derived from the class |
| Balances from `view_account_ledger` at the cut-over date | One `OB` journal per branch | GL accounts at their balance; each **open invoice** of each party posted as a separate line on the control account with `party_id` and `due_date`, so aging carries over; the difference goes to `3199 Opening Balance Equity` (must be zero once complete) |

Validate after import:
1. The new trial balance equals the old trial balance at the cut-over date
   (grouped by the mapping).
2. The new aging per party equals the old aging.
3. `3199 Opening Balance Equity` and `9101 Suspense` are zero.
