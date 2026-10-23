<?php
// Stock Transfer In Detail (item lines)
// Fragment included by modules/stock_transfer_in/stock_transfer_in.php after the header fragment.
// Uses from the header: $is_view_mode, $sti_lines, isEditActive (JS).
// Closes the <form id="stiForm"> opened by header/d_stock_transfer_in_h_01.php.
// When called directly (POST action=sti_items / sti_stock) it answers the item lookup with JSON.
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/erp_paths.php';

$company_code = $_SESSION["company_code"] ?? '';
$branch_code  = $_SESSION["branch_code"]  ?? '';

if (!function_exists('sti_utf8')) {
    function sti_utf8($str)
    {
        return $str === null ? '' : mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
}

// Stock is read from view_item_ledger_summary for the header's Location To (IN),
// the same source the Material Request item lookup uses.
$sti_action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($sti_action === 'sti_items' || $sti_action === 'sti_stock')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $loc = trim($_POST['location'] ?? '');
    try {
        if ($sti_action === 'sti_items') {
            $like = '%' . trim($_POST['term'] ?? '') . '%';
            $stmt = $con->prepare(
                "SELECT TOP 200 i.*, v.stockqty
                 FROM item_master i
                 LEFT JOIN view_item_ledger_summary v
                   ON i.item_code = v.itemcode AND v.locationcode = ?
                 WHERE i.item_code LIKE ? OR i.item_name LIKE ? OR i.oem_code LIKE ?
                 ORDER BY i.item_code"
            );
            $stmt->execute([$loc, $like, $like, $like]);
            $items = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $items[] = [
                    'item_code' => sti_utf8(trim((string) $row['item_code'])),
                    'oem_code'  => isset($row['oem_code']) ? sti_utf8(trim((string) $row['oem_code'])) : '',
                    'item_name' => isset($row['item_name']) ? sti_utf8(trim((string) $row['item_name'])) : '',
                    'unit'      => isset($row['stock_unit']) ? sti_utf8(trim((string) $row['stock_unit'])) : '',
                    'stock'     => isset($row['stockqty']) ? (float) $row['stockqty'] : 0,
                ];
            }
            echo json_encode($items);
        } else {
            $codes = json_decode($_POST['codes'] ?? '[]', true);
            $codes = is_array($codes) ? array_values(array_unique(array_filter(array_map('strval', $codes), 'strlen'))) : [];
            $stock = [];
            foreach ($codes as $c) $stock[$c] = 0;
            if ($codes) {
                $in = implode(',', array_fill(0, count($codes), '?'));
                $stmt = $con->prepare("SELECT itemcode, stockqty FROM view_item_ledger_summary WHERE locationcode = ? AND itemcode IN ($in)");
                $stmt->execute(array_merge([$loc], $codes));
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $stock[trim((string) $row['itemcode'])] = (float) $row['stockqty'];
                }
            }
            echo json_encode($stock);
        }
    } catch (Exception $e) {
        error_log('stock_transfer_in item lookup error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$is_view_mode = $is_view_mode ?? false;
$sti_lines = $sti_lines ?? [];

$unit_list = [];
$stmt_units = $con->prepare("SELECT stock_unit FROM item_unit ORDER BY stock_unit ASC");
$stmt_units->execute([]);
while ($u = $stmt_units->fetch(PDO::FETCH_ASSOC)) {
    $unit_list[] = trim($u['stock_unit']);
}
?>

                <div class="detail-container">
                <div class="detail-header">
                    <h2>Items</h2>
                </div>

                <div class="detail-table-wrapper">
                    <table class="detail-table" id="itemsTable" style="table-layout: fixed;">
                        <thead>
                            <tr>
                                <th style="width: 46px;">No</th>
                                <th style="width: 38px;">Line</th>
                                <th style="width: 100px;">Item Code</th>
                                <th>Item Description</th>
                                <th style="width: 46px;">Loc</th>
                                <th style="width: 70px;">Bin</th>
                                <th style="width: 84px;">Transfer No</th>
                                <th style="width: 76px;">Out Qty</th>
                                <th style="width: 76px;">In Qty</th>
                                <th style="width: 76px;">Bal Qty</th>
                                <th style="width: 66px;">Unit</th>
                                <th style="width: 50px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
                </div><!-- /.detail-container -->

                <div class="doc-actionbar" style="display:none;">
                    <button type="submit" class="detail-btn detail-btn-primary" name="save_sti" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        <i class="fa fa-save"></i> Save
                    </button>
                </div>
            </form>

    <!-- Item Search Modal -->
    <div id="itemSearchModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Item</h4>
                <button type="button" class="erp-search-close" onclick="stiCloseItemSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
            <div class="erp-search-bar">
                <div class="erp-search-input">
                    <i class="fa fa-search"></i>
                    <input type="text" id="itemSearchInput" autocomplete="off"
                        placeholder="Search by Item Code, Name, or OEM Code..." onkeyup="stiSearchItems(this.value)">
                </div>
            </div>
            <div class="erp-search-body">
                <table class="erp-search-table" id="itemSearchResults">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>OEM Code</th>
                            <th>Item Name</th>
                            <th style="width: 90px; text-align: right;">Stock</th>
                            <th style="width: 100px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const STI_AJAX_URL = 'detail/d_stock_transfer_in_d_01.php';
        const STI_UNITS = <?php echo json_encode($unit_list); ?>;
        const STI_LINES = <?php echo json_encode($sti_lines); ?>;
        let stiItemInput = null;
        let stiItemResults = [];

        function stiEsc(v) {
            return String(v == null ? '' : v)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
                .replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function stiLocIn() {
            var el = document.getElementById('stiLocTo');
            return el ? el.value : '';
        }

        function stiUnitOptions(selected) {
            var list = STI_UNITS.slice();
            if (selected && list.indexOf(selected) < 0) list.push(selected);
            return '<option value="">--</option>' + list.map(function (u) {
                return '<option value="' + stiEsc(u) + '"' + (u === selected ? ' selected' : '') + '>' + stiEsc(u) + '</option>';
            }).join('');
        }

        // One item line; locked = saved document in view mode.
        // Item code and description are view only: lines come from a transfer out and
        // the pending balance (view_stk_out_in_balance) matches on them.
        function stiLineHtml(d, locked) {
            var ro = locked ? ' readonly' : '';
            var fromTo = !!d.to_no;
            return '<td data-label="No" class="srl-col"></td>'
                + '<td data-label="Line" class="line-col"><input type="text" name="line_no[]" class="text-center" value="' + stiEsc(d.line) + '" readonly></td>'
                + '<td data-label="Item Code"><input type="text" name="item_code[]" value="' + stiEsc(d.code) + '" readonly></td>'
                + '<td data-label="Item Description"><input type="text" name="item_desc[]" maxlength="250" value="' + stiEsc(d.desc) + '" readonly>'
                +     '<input type="hidden" name="stock[]" value="' + (parseFloat(d.stock) || 0).toFixed(2) + '">'
                +     '<div style="padding: 0 5px; font-size: 11px; color: var(--text-3);">Stock: '
                +         '<span class="sti-stock-txt" style="color: var(--danger); font-weight: 600;">' + (parseFloat(d.stock) || 0).toFixed(2) + '</span></div></td>'
                + '<td data-label="Loc"><input type="text" name="location[]" class="text-center" value="' + stiEsc(d.loc) + '" readonly></td>'
                + '<td data-label="Bin"><input type="text" name="bin[]" maxlength="15" value="' + stiEsc(d.bin) + '"' + ro + '></td>'
                + '<td data-label="Transfer No"><input type="text" name="transfer_outno[]" class="text-center" value="' + stiEsc(d.to_no || '') + '" readonly></td>'
                + '<td data-label="Out Qty"><input type="text" name="transfer_outqty[]" class="text-center" value="' + (fromTo ? (parseFloat(d.to_qty) || 0).toFixed(2) : '') + '" readonly></td>'
                + '<td data-label="In Qty"><input type="number" name="qty[]" step="0.01" min="0" class="text-center" value="' + (d.qty !== '' && d.qty != null ? stiEsc(d.qty) : '0') + '"' + ro + '></td>'
                + '<td data-label="Bal Qty"><input type="text" class="text-center sti-bal" value="" readonly></td>'
                + '<td data-label="Unit"><select name="unit[]" class="text-center"' + (locked ? ' disabled' : '') + '>' + stiUnitOptions(d.unit || '') + '</select></td>'
                + '<td data-label="Action" class="text-center line-del">'
                +     '<button type="button" class="btn btn-outline-danger btn-sm" onclick="stiDeleteRow(this)"' + (locked ? ' disabled' : '') + '>'
                +         '<i class="fa fa-trash"></i></button></td>';
        }

        function stiReindex() {
            var rows = document.getElementById('itemsBody').rows;
            for (var i = 0; i < rows.length; i++) {
                rows[i].cells[0].textContent = String(i + 1).padStart(4, '0');
            }
            stiUpdateSummary();
        }

        function stiAppendLine(d, locked) {
            var tr = document.getElementById('itemsBody').insertRow();
            tr.className = 'detail-row';
            tr.innerHTML = stiLineHtml(d, locked);
            if (d.to_no) tr.setAttribute('data-to-bal', parseFloat(d.to_bal) || 0);
            stiUpdateBal(tr);
            stiReindex();
            return tr;
        }

        function stiSetStock(tr, v) {
            var s = (parseFloat(v) || 0).toFixed(2);
            tr.querySelector('input[name="stock[]"]').value = s;
            tr.querySelector('.sti-stock-txt').textContent = s;
        }

        // Bal Qty = pending on the transfer out before this document - In Qty
        function stiUpdateBal(tr) {
            var bal = tr.querySelector('.sti-bal');
            if (!bal) return;
            if (!tr.hasAttribute('data-to-bal')) { bal.value = ''; return; }
            var v = (parseFloat(tr.getAttribute('data-to-bal')) || 0)
                - (parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0);
            bal.value = v.toFixed(2);
            bal.style.color = v < -0.0001 ? 'var(--danger)' : '';
        }

        function stiDeleteRow(btn) {
            var tr = btn.closest('tr');
            if (tr) tr.remove();
            stiReindex();
        }

        // Side panel summary: line count and total quantity
        function stiUpdateSummary() {
            var lines = 0, qty = 0;
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                var code = tr.querySelector('input[name="item_code[]"]');
                if (!code || !code.value.trim()) return;
                lines++;
                qty += parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0;
            });
            var l = document.getElementById('stiSumLines'), q = document.getElementById('stiSumQty');
            if (l) l.textContent = lines;
            if (q) q.textContent = qty.toFixed(2);
        }
        document.getElementById('itemsBody').addEventListener('input', function (e) {
            if (e.target && e.target.name === 'qty[]') stiUpdateBal(e.target.closest('tr'));
            stiUpdateSummary();
        });

        function stiPost(params) {
            var fd = new FormData();
            Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
            return fetch(STI_AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('Server returned ' + r.status);
                    return r.json();
                });
        }

        // Location To changed: every line follows it and its stock is re-read
        function stiRefreshLines() {
            var loc = stiLocIn();
            var codes = [];
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                var locEl = tr.querySelector('input[name="location[]"]');
                if (locEl) locEl.value = loc;
                var code = tr.querySelector('input[name="item_code[]"]').value.trim();
                if (code) codes.push(code);
            });
            if (!codes.length) return;
            stiPost({ action: 'sti_stock', location: loc, codes: JSON.stringify(codes) })
                .then(function (stock) {
                    if (!stock || stock.error) return;
                    document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                        var code = tr.querySelector('input[name="item_code[]"]').value.trim();
                        if (code && Object.prototype.hasOwnProperty.call(stock, code)) {
                            stiSetStock(tr, stock[code]);
                        }
                    });
                })
                .catch(function (err) { console.error('Stock refresh error:', err); });
        }

        // ---- Item lookup ----
        function stiOpenItemSearch(input) {
            if (!isEditActive) return;
            if (!stiLocIn()) {
                alert('Please select Location To (IN) first.');
                return;
            }
            stiItemInput = input;
            document.getElementById('itemSearchModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            var box = document.getElementById('itemSearchInput');
            box.value = '';
            box.focus();
            stiSearchItems('');
        }

        function stiCloseItemSearch() {
            document.getElementById('itemSearchModal').style.display = 'none';
            document.body.style.overflow = '';
            stiItemInput = null;
        }

        var stiSearchTimer;
        function stiSearchItems(query) {
            clearTimeout(stiSearchTimer);
            stiSearchTimer = setTimeout(function () {
                var tbody = document.querySelector('#itemSearchResults tbody');
                stiPost({ action: 'sti_items', term: query, location: stiLocIn() })
                    .then(function (data) {
                        tbody.innerHTML = '';
                        if (data && data.error) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-center p-3 text-danger">Error: ' + stiEsc(data.error) + '</td></tr>';
                            return;
                        }
                        if (!Array.isArray(data) || !data.length) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-center p-3">No items found</td></tr>';
                            return;
                        }
                        stiItemResults = data;
                        data.forEach(function (item, i) {
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td>' + stiEsc(item.item_code) + '</td>'
                                + '<td>' + stiEsc(item.oem_code) + '</td>'
                                + '<td>' + stiEsc(item.item_name) + '</td>'
                                + '<td class="text-right">' + (parseFloat(item.stock) || 0).toFixed(2) + '</td>'
                                + '<td class="text-center"><button type="button" class="btn btn-primary btn-sm" onclick="stiSelectItem(' + i + ')">Select</button></td>';
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(function (err) {
                        console.error('Error fetching items:', err);
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                    });
            }, 300);
        }

        function stiSelectItem(i) {
            var item = stiItemResults[i];
            if (item && stiItemInput) {
                var tr = stiItemInput.closest('tr');
                stiItemInput.value = item.item_code;
                tr.querySelector('input[name="item_desc[]"]').value = item.item_name;
                tr.querySelector('input[name="location[]"]').value = stiLocIn();
                stiSetStock(tr, item.stock);
                tr.querySelector('select[name="unit[]"]').innerHTML = stiUnitOptions(item.unit || '');
                var qty = tr.querySelector('input[name="qty[]"]');
                stiCloseItemSearch();
                stiUpdateSummary();
                if (qty) qty.select();
                return;
            }
            stiCloseItemSearch();
        }

        // Called by the header's enableEditMode() after the edit password is verified
        function stiDetailEnableEdit() {
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                ['input[name="bin[]"]', 'input[name="qty[]"]'].forEach(function (sel) {
                    var el = tr.querySelector(sel);
                    if (el) el.removeAttribute('readonly');
                });
                var unit = tr.querySelector('select[name="unit[]"]');
                if (unit) unit.disabled = false;
                var del = tr.querySelector('.line-del button');
                if (del) del.disabled = false;
            });
        }

        STI_LINES.forEach(function (d) { stiAppendLine(d, <?php echo $is_view_mode ? 'true' : 'false'; ?>); });
        stiUpdateSummary();
    </script>
