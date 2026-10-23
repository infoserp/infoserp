<?php
// Stock Transfer Out Detail (item lines)
// Fragment included by modules/stock_transfer_out/stock_transfer_out.php after the header fragment.
// Uses from the header: $is_view_mode, $sto_lines, $location_from, isEditActive (JS).
// Closes the <form id="stoForm"> opened by header/d_stock_transfer_out_h_01.php.
// When called directly (POST action=sto_items / sto_stock) it answers the item lookup with JSON.
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/erp_paths.php';

$company_code = $_SESSION["company_code"] ?? '';
$branch_code  = $_SESSION["branch_code"]  ?? '';

if (!function_exists('sto_utf8')) {
    function sto_utf8($str)
    {
        return $str === null ? '' : mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
}

// Stock is read from view_item_ledger_summary for the header's Location From (OUT),
// the same source the Material Request item lookup uses.
$sto_action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($sto_action === 'sto_items' || $sto_action === 'sto_stock')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $loc = trim($_POST['location'] ?? '');
    try {
        if ($sto_action === 'sto_items') {
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
                    'item_code' => sto_utf8(trim((string) $row['item_code'])),
                    'oem_code'  => isset($row['oem_code']) ? sto_utf8(trim((string) $row['oem_code'])) : '',
                    'item_name' => isset($row['item_name']) ? sto_utf8(trim((string) $row['item_name'])) : '',
                    'unit'      => isset($row['stock_unit']) ? sto_utf8(trim((string) $row['stock_unit'])) : '',
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
        error_log('stock_transfer_out item lookup error: ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$is_view_mode = $is_view_mode ?? false;
$sto_lines = $sto_lines ?? [];

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
                    <table class="detail-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="min-width: 60px;">No</th>
                                <th style="min-width: 60px;">Line</th>
                                <th style="min-width: 150px;">Item Code</th>
                                <th style="min-width: 300px;">Item Description</th>
                                <th style="min-width: 80px;">Loc</th>
                                <th style="min-width: 100px;">Bin</th>
                                <th style="min-width: 100px;">Stock</th>
                                <th style="min-width: 100px;">Qty</th>
                                <th style="min-width: 80px;">Unit</th>
                                <th style="min-width: 60px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>

                <div class="detail-toolbar detail-toolbar-bottom">
                    <button type="button" class="detail-btn detail-btn-link" id="addLineBtn" onclick="stoAddLine()" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        <i class="fa fa-plus"></i> Add line
                    </button>
                </div>
                </div><!-- /.detail-container -->

                <div class="doc-actionbar" style="display:none;">
                    <button type="submit" class="detail-btn detail-btn-primary" name="save_sto" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        <i class="fa fa-save"></i> Save
                    </button>
                </div>
            </form>

    <!-- Item Search Modal -->
    <div id="itemSearchModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Item</h4>
                <button type="button" class="erp-search-close" onclick="stoCloseItemSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
            <div class="erp-search-bar">
                <div class="erp-search-input">
                    <i class="fa fa-search"></i>
                    <input type="text" id="itemSearchInput" autocomplete="off"
                        placeholder="Search by Item Code, Name, or OEM Code..." onkeyup="stoSearchItems(this.value)">
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
        const STO_AJAX_URL = 'detail/d_stock_transfer_out_d_01.php';
        const STO_UNITS = <?php echo json_encode($unit_list); ?>;
        const STO_LINES = <?php echo json_encode($sto_lines); ?>;
        const STO_ALLOW_ZERO_STOCK = <?php
            require_once __DIR__ . '/../../../includes/erp_user_access.php';
            echo json_encode(erp_user_allow_zero_stock($con));
        ?>;
        let stoItemInput = null;
        let stoItemResults = [];

        function stoEsc(v) {
            return String(v == null ? '' : v)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
                .replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function stoLocFrom() {
            var el = document.getElementById('stoLocFrom');
            return el ? el.value : '';
        }

        function stoUnitOptions(selected) {
            var list = STO_UNITS.slice();
            if (selected && list.indexOf(selected) < 0) list.push(selected);
            return '<option value="">--</option>' + list.map(function (u) {
                return '<option value="' + stoEsc(u) + '"' + (u === selected ? ' selected' : '') + '>' + stoEsc(u) + '</option>';
            }).join('');
        }

        // One item line; locked = saved document in view mode
        function stoLineHtml(d, locked) {
            var ro = locked ? ' readonly' : '';
            return '<td data-label="No" class="srl-col"></td>'
                + '<td data-label="Line" class="line-col"><input type="text" name="line_no[]" class="text-center" value="' + stoEsc(d.line) + '" readonly></td>'
                + '<td data-label="Item Code"><input type="text" name="item_code[]" value="' + stoEsc(d.code) + '" readonly'
                +     (locked ? '' : ' placeholder="Click to select" onclick="stoOpenItemSearch(this)"') + '></td>'
                + '<td data-label="Item Description"><input type="text" name="item_desc[]" maxlength="250" value="' + stoEsc(d.desc) + '"' + ro + '></td>'
                + '<td data-label="Loc"><input type="text" name="location[]" class="text-center" value="' + stoEsc(d.loc) + '" readonly></td>'
                + '<td data-label="Bin"><input type="text" name="bin[]" maxlength="15" value="' + stoEsc(d.bin) + '"' + ro + '></td>'
                + '<td data-label="Stock"><input type="text" name="stock[]" class="text-center stock-input" style="color: var(--danger);" value="' + (parseFloat(d.stock) || 0).toFixed(2) + '" readonly></td>'
                + '<td data-label="Qty"><input type="number" name="qty[]" step="0.01" min="0" class="text-center" value="' + (d.qty !== '' && d.qty != null ? stoEsc(d.qty) : '0') + '"' + ro + '></td>'
                + '<td data-label="Unit"><select name="unit[]" class="text-center"' + (locked ? ' disabled' : '') + '>' + stoUnitOptions(d.unit || '') + '</select></td>'
                + '<td data-label="Action" class="text-center line-del">'
                +     '<button type="button" class="btn btn-outline-danger btn-sm" onclick="stoDeleteRow(this)"' + (locked ? ' disabled' : '') + '>'
                +         '<i class="fa fa-trash"></i></button></td>';
        }

        function stoReindex() {
            var rows = document.getElementById('itemsBody').rows;
            for (var i = 0; i < rows.length; i++) {
                rows[i].cells[0].textContent = String(i + 1).padStart(4, '0');
            }
            stoUpdateSummary();
        }

        function stoAppendLine(d, locked) {
            var tr = document.getElementById('itemsBody').insertRow();
            tr.className = 'detail-row';
            tr.innerHTML = stoLineHtml(d, locked);
            stoReindex();
            return tr;
        }

        function stoAddLine() {
            if (!isEditActive) return;
            var max = 0;
            document.querySelectorAll('#itemsBody input[name="line_no[]"]').forEach(function (el) {
                max = Math.max(max, parseInt(el.value, 10) || 0);
            });
            return stoAppendLine({ line: max + 1, code: '', desc: '', loc: stoLocFrom(), bin: '', stock: 0, qty: 0, unit: '' }, false);
        }

        function stoDeleteRow(btn) {
            var tr = btn.closest('tr');
            if (tr) tr.remove();
            stoReindex();
        }

        // Side panel summary: line count and total quantity
        function stoUpdateSummary() {
            var lines = 0, qty = 0;
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                var code = tr.querySelector('input[name="item_code[]"]');
                if (!code || !code.value.trim()) return;
                lines++;
                qty += parseFloat((tr.querySelector('input[name="qty[]"]') || {}).value) || 0;
            });
            var l = document.getElementById('stoSumLines'), q = document.getElementById('stoSumQty');
            if (l) l.textContent = lines;
            if (q) q.textContent = qty.toFixed(2);
        }
        // Qty > stock validation (branch_user.allow_zero_stock = 'Y' skips it)
        document.getElementById('itemsBody').addEventListener('input', function (e) {
            if (STO_ALLOW_ZERO_STOCK === 'Y' || !e.target || e.target.name !== 'qty[]') return;
            var tr = e.target.closest('tr');
            var stockQty = parseFloat((tr.querySelector('input[name="stock[]"]') || {}).value) || 0;
            if ((parseFloat(e.target.value) || 0) > stockQty) {
                alert('Entered Qty is more than the Stock..');
                e.target.value = Math.max(stockQty, 0).toFixed(2);
            }
        });
        document.getElementById('itemsBody').addEventListener('input', stoUpdateSummary);

        function stoPost(params) {
            var fd = new FormData();
            Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
            return fetch(STO_AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('Server returned ' + r.status);
                    return r.json();
                });
        }

        // Location From changed: every line follows it and its stock is re-read
        function stoRefreshLines() {
            var loc = stoLocFrom();
            var codes = [];
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                var locEl = tr.querySelector('input[name="location[]"]');
                if (locEl) locEl.value = loc;
                var code = tr.querySelector('input[name="item_code[]"]').value.trim();
                if (code) codes.push(code);
            });
            if (!codes.length) return;
            stoPost({ action: 'sto_stock', location: loc, codes: JSON.stringify(codes) })
                .then(function (stock) {
                    if (!stock || stock.error) return;
                    document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                        var code = tr.querySelector('input[name="item_code[]"]').value.trim();
                        if (code && Object.prototype.hasOwnProperty.call(stock, code)) {
                            tr.querySelector('input[name="stock[]"]').value = (parseFloat(stock[code]) || 0).toFixed(2);
                        }
                    });
                })
                .catch(function (err) { console.error('Stock refresh error:', err); });
        }

        // ---- Item lookup ----
        function stoOpenItemSearch(input) {
            if (!isEditActive) return;
            if (!stoLocFrom()) {
                alert('Please select Location From (OUT) first.');
                return;
            }
            stoItemInput = input;
            document.getElementById('itemSearchModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            var box = document.getElementById('itemSearchInput');
            box.value = '';
            box.focus();
            stoSearchItems('');
        }

        function stoCloseItemSearch() {
            document.getElementById('itemSearchModal').style.display = 'none';
            document.body.style.overflow = '';
            stoItemInput = null;
        }

        var stoSearchTimer;
        function stoSearchItems(query) {
            clearTimeout(stoSearchTimer);
            stoSearchTimer = setTimeout(function () {
                var tbody = document.querySelector('#itemSearchResults tbody');
                stoPost({ action: 'sto_items', term: query, location: stoLocFrom() })
                    .then(function (data) {
                        tbody.innerHTML = '';
                        if (data && data.error) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-center p-3 text-danger">Error: ' + stoEsc(data.error) + '</td></tr>';
                            return;
                        }
                        if (!Array.isArray(data) || !data.length) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-center p-3">No items found</td></tr>';
                            return;
                        }
                        stoItemResults = data;
                        data.forEach(function (item, i) {
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td>' + stoEsc(item.item_code) + '</td>'
                                + '<td>' + stoEsc(item.oem_code) + '</td>'
                                + '<td>' + stoEsc(item.item_name) + '</td>'
                                + '<td class="text-right">' + (parseFloat(item.stock) || 0).toFixed(2) + '</td>'
                                + '<td class="text-center"><button type="button" class="btn btn-primary btn-sm" onclick="stoSelectItem(' + i + ')">Select</button></td>';
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(function (err) {
                        console.error('Error fetching items:', err);
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                    });
            }, 300);
        }

        function stoSelectItem(i) {
            var item = stoItemResults[i];
            if (item && stoItemInput) {
                var tr = stoItemInput.closest('tr');
                stoItemInput.value = item.item_code;
                tr.querySelector('input[name="item_desc[]"]').value = item.item_name;
                tr.querySelector('input[name="location[]"]').value = stoLocFrom();
                tr.querySelector('input[name="stock[]"]').value = (parseFloat(item.stock) || 0).toFixed(2);
                tr.querySelector('select[name="unit[]"]').innerHTML = stoUnitOptions(item.unit || '');
                var qty = tr.querySelector('input[name="qty[]"]');
                stoCloseItemSearch();
                stoUpdateSummary();
                if (qty) qty.select();
                return;
            }
            stoCloseItemSearch();
        }

        // Called by the header's enableEditMode() after the edit password is verified
        function stoDetailEnableEdit() {
            document.getElementById('addLineBtn').removeAttribute('disabled');
            document.querySelectorAll('#itemsBody tr').forEach(function (tr) {
                ['input[name="item_desc[]"]', 'input[name="bin[]"]', 'input[name="qty[]"]'].forEach(function (sel) {
                    var el = tr.querySelector(sel);
                    if (el) el.removeAttribute('readonly');
                });
                var unit = tr.querySelector('select[name="unit[]"]');
                if (unit) unit.disabled = false;
                var del = tr.querySelector('.line-del button');
                if (del) del.disabled = false;
                var code = tr.querySelector('input[name="item_code[]"]');
                if (code) {
                    code.placeholder = 'Click to select';
                    code.onclick = function () { stoOpenItemSearch(this); };
                }
            });
        }

        STO_LINES.forEach(function (d) { stoAppendLine(d, <?php echo $is_view_mode ? 'true' : 'false'; ?>); });
        <?php if (!$is_view_mode): ?>
        if (!document.getElementById('itemsBody').rows.length) stoAddLine();
        <?php endif; ?>
        stoUpdateSummary();
    </script>
