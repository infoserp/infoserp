<?php
// Server-side Qty vs Stock check used by the detail save handlers
// (branch_user.allow_zero_stock = 'Y' skips it, see erp_user_allow_zero_stock()).
require_once __DIR__ . '/erp_user_access.php';

if (!function_exists('erp_stock_shortages')) {
    /**
     * Lines whose total Qty per location + item is more than the stock in
     * view_item_ledger_summary.
     *
     * @param list<array{location:string,item_code:string,qty:float|string}> $lines
     * @param array<string,float> $own_qty "location|item" => qty this document already
     *        holds in the ledger (its previously saved lines); added back to the stock
     *        so re-saving an existing document does not count its own qty twice.
     * @return list<array{location:string,item_code:string,qty:float,stock:float}>
     */
    function erp_stock_shortages(PDO $con, array $lines, array $own_qty = [])
    {
        $need = [];
        foreach ($lines as $ln) {
            $item = trim((string) ($ln['item_code'] ?? ''));
            $qty  = (float) ($ln['qty'] ?? 0);
            if ($item === '' || $qty <= 0) {
                continue;
            }
            $loc = trim((string) ($ln['location'] ?? ''));
            $key = $loc . '|' . $item;
            if (!isset($need[$key])) {
                $need[$key] = ['location' => $loc, 'item_code' => $item, 'qty' => 0.0];
            }
            $need[$key]['qty'] += $qty;
        }
        if (!$need) {
            return [];
        }

        $by_loc = [];
        foreach ($need as $n) {
            $by_loc[$n['location']][] = $n['item_code'];
        }

        $stock = [];
        foreach ($by_loc as $loc => $items) {
            $items = array_values(array_unique($items));
            $in = implode(',', array_fill(0, count($items), '?'));
            $stmt = $con->prepare(
                "SELECT itemcode, SUM(stockqty) AS stockqty
                 FROM   view_item_ledger_summary
                 WHERE  locationcode = ? AND itemcode IN ($in)
                 GROUP BY itemcode"
            );
            $stmt->execute(array_merge([$loc], $items));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stock[$loc . '|' . trim((string) $row['itemcode'])] = (float) $row['stockqty'];
            }
        }

        $short = [];
        foreach ($need as $key => $n) {
            $available = ($stock[$key] ?? 0.0) + (float) ($own_qty[$key] ?? 0);
            if ($n['qty'] > $available + 0.00001) {
                $n['stock'] = $available;
                $short[] = $n;
            }
        }
        return $short;
    }
}

if (!function_exists('erp_stock_shortage_message')) {
    function erp_stock_shortage_message(array $short)
    {
        $parts = [];
        foreach ($short as $s) {
            $parts[] = $s['item_code'] . ' (Qty ' . number_format($s['qty'], 2, '.', '')
                     . ', Stock ' . number_format($s['stock'], 2, '.', '') . ')';
        }
        return 'Entered Qty is more than the Stock.. ' . implode(', ', $parts);
    }
}
