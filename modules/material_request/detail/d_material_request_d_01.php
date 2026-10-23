<?php
// Material Request Detail (item lines)
// Fragment included by modules/material_request/material_request.php after the header fragment.
// Uses from the header: $is_view_mode, $edit_details, $selected_doc_type, isEditActive / DOC_APPROVED (JS).
// Closes the <form id="mrForm"> opened by header/d_material_request_h_01.php.
if (!isset($con)) {
    require('../../../db.php');
    include('../../../auth.php');
}
require_once __DIR__ . '/../../../includes/erp_paths.php';

$company_code = $_SESSION["company_code"];
$branch_code = $_SESSION["branch_code"];
$is_view_mode = $is_view_mode ?? false;
$edit_details = $edit_details ?? [];
$selected_doc_type = $selected_doc_type ?? '';

// Fetch unit list for dropdown
$unit_list = [];
$stmt_units = $con->prepare("SELECT stock_unit FROM item_unit ORDER BY stock_unit ASC");
$stmt_units->execute([]);
while ($u = $stmt_units->fetch(PDO::FETCH_ASSOC)) {
    $unit_list[] = trim($u['stock_unit']);
}
$unit_options_js = json_encode($unit_list);

// Fetch key_lock from control_insert_key to control Add New Line button
$insert_key_lock = 'N';
$stmt_key = $con->prepare("SELECT key_lock FROM control_insert_key WHERE company_code = ? AND branch_code = ? AND doc_type = ?");
$stmt_key->execute(array($company_code, $branch_code, $selected_doc_type));
$row_key = $stmt_key->fetch(PDO::FETCH_ASSOC);
if ($row_key && isset($row_key['key_lock'])) {
    $insert_key_lock = strtoupper(trim($row_key['key_lock']));
}
?>

                <div class="detail-container">
                <div class="detail-header">
                    <h2>Items</h2>
                </div>

                <!-- Items Table -->
                <div class="detail-table-wrapper">
                    <table class="detail-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="min-width: 60px;">Srl</th>
                                <th style="min-width: 60px;">Line</th>
                                <th style="min-width: 150px;">Activity</th>
                                <th style="min-width: 150px;">Item Code</th>
                                <th style="min-width: 300px;">Item Description</th>
                                <th style="min-width: 100px;">Stock</th>
                                <th style="min-width: 100px;">Rq.Qty</th>
                                <th style="min-width: 100px;">Apr.Qty</th>
                                <th style="min-width: 80px;">Unit</th>
                                <th style="min-width: 130px;">Expected Dt.</th>
                                <th style="min-width: 200px;">Job No</th>
                                <th style="min-width: 150px;">Vehicle No</th>
                                <th style="min-width: 150px;">Remark</th>
                                <th style="min-width: 60px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <?php
                            if ($is_view_mode && !empty($edit_details)) {
                                foreach ($edit_details as $index => $det) {
                                    $srl = str_pad($index + 1, 4, "0", STR_PAD_LEFT);
                                    $row_id = "row" . ($index + 1);

                                    // Make sure values are safely escaped
                                    $i_ac_subcode = isset($det['ac_subcode']) ? htmlspecialchars($det['ac_subcode'], ENT_QUOTES) : '';
                                    $i_ac_name = isset($det['ac_name']) && trim($det['ac_name']) !== '' ? htmlspecialchars($det['ac_name'], ENT_QUOTES) : $i_ac_subcode;
                                    $i_item_code = htmlspecialchars($det['item_code'], ENT_QUOTES);
                                    $i_item_desc = htmlspecialchars($det['item_description'], ENT_QUOTES);
                                    $i_stock = number_format((float)$det['stock_qty'], 2, '.', '');
                                    $i_rq_qty = htmlspecialchars($det['request_qty'], ENT_QUOTES);
                                    $i_apr_qty = htmlspecialchars($det['trans_qty'], ENT_QUOTES);
                                    $i_unit = htmlspecialchars($det['item_unit'], ENT_QUOTES);
                                    $i_exp_dt = isset($det['expected_date']) ? htmlspecialchars($det['expected_date'], ENT_QUOTES) : '';
                                    if ($i_exp_dt && strpos($i_exp_dt, ' ') !== false) {
                                        $i_exp_dt = explode(' ', $i_exp_dt)[0]; // get YYYY-MM-DD
                                    }
                                    $i_job_no = isset($det['job_no']) ? htmlspecialchars($det['job_no'], ENT_QUOTES) : '';
                                    $i_vehicle = isset($det['vehicle_no']) ? htmlspecialchars($det['vehicle_no'], ENT_QUOTES) : '';
                                    $i_remark = isset($det['remark']) ? htmlspecialchars($det['remark'], ENT_QUOTES) : '';
                                    $i_row_approved = strtoupper(trim($det['approved'] ?? 'N'));

                                    // Build unit dropdown options
                                    $unit_options_html = "<option value=''>--</option>";
                                    foreach ($unit_list as $ul) {
                                        $ul_esc = htmlspecialchars($ul);
                                        $sel = ($i_unit === $ul_esc) ? " selected" : "";
                                        $unit_options_html .= "<option value='{$ul_esc}'{$sel}>{$ul_esc}</option>";
                                    }

                                    echo "<tr id='{$row_id}' class='detail-row' data-row-approved='{$i_row_approved}'>
                                        <td data-label='Srl' class='srl-col'>{$srl}</td>
                                        <td data-label='Line' class='line-col'><input type='text' name='line_no[]' class='text-center' value='{$det['item_no']}' readonly></td>
                                        <td data-label='Activity'>
                                            <input type='hidden' name='ac_subcode[]' value='{$i_ac_subcode}'>
                                            <input type='text' name='ac_name[]' value='{$i_ac_name}' readonly>
                                        </td>
                                        <td data-label='Item Code'><input type='text' name='item_code[]' value='{$i_item_code}' readonly></td>
                                        <td data-label='Description'><input type='text' name='item_desc[]' value='{$i_item_desc}' readonly></td>
                                        <td data-label='Stock'><input type='text' name='stock[]' class='text-center stock-input' value='{$i_stock}' readonly></td>
                                        <td data-label='Rq.Qty'><input type='number' name='rq_qty[]' class='text-center' value='{$i_rq_qty}' readonly></td>
                                        <td data-label='Apr.Qty'><input type='number' name='apr_qty[]' class='text-center' value='{$i_apr_qty}' readonly></td>
                                        <td data-label='Unit'>
                                            <select name='unit[]' class='text-center' disabled>
                                                {$unit_options_html}
                                            </select>
                                        </td>
                                        <td data-label='Exp. Date'><input type='date' name='expected_dt[]' class='text-center' value='{$i_exp_dt}' readonly></td>
                                        <td data-label='Job No'><input type='text' name='job_no[]' value='{$i_job_no}' readonly></td>
                                        <td data-label='Vehicle No'><input type='text' name='vehicle_no[]' value='{$i_vehicle}' readonly></td>
                                        <td data-label='Remark'>
                                            <input type='hidden' name='item_remark[]' value='{$i_remark}'>
                                            <span style='font-size: 0.8rem;'>{$i_remark}</span>
                                        </td>
                                        <td data-label='Action' class='text-center line-del'>
                                            <button type='button' class='btn btn-outline-danger btn-sm' onclick='deleteRow(\"{$row_id}\")' disabled>
                                                <i class='fa fa-trash'></i>
                                            </button>
                                        </td>
                                    </tr>";
                                }
                            } else {
                                // No default row — user must click "Add New Line"
                            ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <div class="detail-toolbar detail-toolbar-bottom">
                    <button type="button" class="detail-btn detail-btn-link" id="addLineBtn" onclick="addLine()" <?php echo ($is_view_mode || $insert_key_lock === 'Y') ? 'disabled' : ''; ?>>
                        <i class="fa fa-plus"></i> Add line
                    </button>
                    <button type="button" class="detail-btn detail-btn-link" id="bomBtn" onclick="openJobBomModal(true)" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        <i class="fa fa-sitemap"></i> Select Job BOM
                    </button>
                    <!-- <button type="button" class="detail-btn detail-btn-secondary" onclick="openJobBomModal(false)">Select Job Bill of
                        Material</button> -->
                </div>
                </div><!-- /.detail-container -->

                <div class="doc-actionbar" style="display:none;">
                    <a href="<?php echo htmlspecialchars(erp_iframe_url('dashboard'), ENT_QUOTES, 'UTF-8'); ?>" class="detail-btn detail-btn-secondary">Back</a>
                    <button type="submit" class="detail-btn detail-btn-primary" name="save_mr" <?php echo $is_view_mode ? 'disabled' : ''; ?>>
                        <i class="fa fa-save"></i> Save
                    </button>
                </div>
            </form>

    <!-- Remark Popup Modal -->
    <div id="remarkPopup" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog sm" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-comment-o"></i>Enter Remark</h4>
                <button type="button" class="erp-search-close" onclick="closeRemarkPopup()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
            <div class="erp-search-content">
                <textarea id="popupRemarkText" class="erp-search-textarea" rows="5" placeholder="Type your remarks here..."></textarea>
            </div>
            <div class="erp-search-foot">
                <button type="button" class="erp-search-btn" onclick="closeRemarkPopup()">Cancel</button>
                <button type="button" class="erp-search-btn primary" onclick="saveRemark()">Save Remark</button>
            </div>
        </div>
    </div>

    <script>
        let currentRemarkInput = null;

        // ---- Unit dropdown helper ----
        const UNIT_LIST = <?php echo $unit_options_js; ?>;

        function populateUnitSelect(sel, selectedVal) {
            sel.innerHTML = '<option value="">--</option>';
            UNIT_LIST.forEach(function(u) {
                const opt = document.createElement('option');
                opt.value = u;
                opt.textContent = u;
                if (u === selectedVal) opt.selected = true;
                sel.appendChild(opt);
            });
        }

        function openRemarkPopup(linkElement) {
            const td = linkElement.closest('td');
            currentRemarkInput = td.querySelector('input[name="item_remark[]"]');

            const popup = document.getElementById('remarkPopup');
            const textarea = document.getElementById('popupRemarkText');

            textarea.value = currentRemarkInput.value;
            popup.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            textarea.focus();
        }

        function closeRemarkPopup() {
            document.getElementById('remarkPopup').style.display = 'none';
            document.body.style.overflow = '';
            currentRemarkInput = null;
        }

        function saveRemark() {
            if (currentRemarkInput) {
                currentRemarkInput.value = document.getElementById('popupRemarkText').value;
            }
            closeRemarkPopup();
        }



        function addLine() {
            const tbody = document.getElementById('itemsBody');
            const rowCount = tbody.rows.length + 1;
            const newRow = tbody.insertRow();
            newRow.id = "row" + rowCount;
            newRow.className = 'detail-row';

            const nextSrl = rowCount.toString().padStart(4, '0');

            newRow.innerHTML = `
                <td data-label="Srl" class="srl-col">${nextSrl}</td>
                <td data-label="Line" class="line-col"><input type="text" name="line_no[]" class="text-center" value="${rowCount}" readonly></td>
                <td data-label="Activity">
                    <input type="hidden" name="ac_subcode[]">
                    <input type="text" name="ac_name[]" onclick="openActivitySearch(this)" readonly placeholder="Click to select">
                </td>
                <td data-label="Item Code"><input type="text" name="item_code[]" onclick="openItemSearch(this)" readonly placeholder="Click to select"></td>
                <td data-label="Description"><input type="text" name="item_desc[]" required></td>
                <td data-label="Stock"><input type="text" name="stock[]" class="text-center stock-input" value="0" readonly></td>
                <td data-label="Rq.Qty"><input type="number" name="rq_qty[]" step="0.01" class="text-center" value="0" required oninput="copyQty(this)"></td>
                <td data-label="Apr.Qty"><input type="number" name="apr_qty[]" step="0.01" class="text-center" value="0" required readonly></td>
                <td data-label="Unit">
                    <select name="unit[]" class="text-center">
                        <option value="">--</option>
                    </select>
                </td>
                <td data-label="Exp. Date"><input type="date" name="expected_dt[]" class="text-center"></td>
                <td data-label="Job No"><input type="text" name="job_no[]" onclick="openJobSearch(this)" readonly placeholder="Click to select"></td>
                <td data-label="Vehicle No"><input type="text" name="vehicle_no[]" onclick="openVehicleSearch(this)" readonly placeholder="Click to select"></td>
                <td data-label="Remark">
                    <input type="hidden" name="item_remark[]">
                    <a href="javascript:void(0)" onclick="openRemarkPopup(this)" style="color: var(--primary); font-weight: 500; font-size: 0.9rem;">more..</a>
                </td>
                <td data-label="Action" class="text-center line-del">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteRow('row${rowCount}')">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            `;
            // Populate unit dropdown for the new row
            const unitSel = newRow.querySelector('select[name="unit[]"]');
            if (unitSel) populateUnitSelect(unitSel, '');
        }

        function deleteRow(rowId) {
            const row = document.getElementById(rowId);
            if (row) {
                row.remove();
                reindexRows();
            }
        }

        function reindexRows() {
            const tbody = document.getElementById('itemsBody');
            const rows = tbody.rows;
            for (let i = 0; i < rows.length; i++) {
                const slNo = i + 1;
                const nextSrl = slNo.toString().padStart(4, '0');

                rows[i].id = "row" + slNo;
                rows[i].cells[0].innerText = nextSrl;

                const delBtn = rows[i].querySelector('button[onclick^="deleteRow"]');
                if (delBtn) delBtn.setAttribute('onclick', `deleteRow('row${slNo}')`);
            }
        }

        document.getElementById('itemsTable').addEventListener('keydown', function (e) {
            if (e.ctrlKey && (e.key === 'Delete' || e.keyCode === 46)) {
                const row = e.target.closest('tr');
                if (row) {
                    const tbody = document.getElementById('itemsBody');
                    if (tbody.rows.length > 1) {
                        row.remove();
                        reindexRows();
                        e.preventDefault();
                    }
                }
            }
        });

        function copyQty(input) {
            const row = input.closest('tr');
            const aprQtyInput = row.querySelector('input[name="apr_qty[]"]');
            if (aprQtyInput) {
                aprQtyInput.value = input.value;
            }
        }

        // Qty > stock validation (branch_user.allow_zero_stock = 'Y' skips it)
        const MR_ALLOW_ZERO_STOCK = <?php
            require_once __DIR__ . '/../../../includes/erp_user_access.php';
            echo json_encode(erp_user_allow_zero_stock($con));
        ?>;
        document.getElementById('itemsBody').addEventListener('input', function (e) {
            if (MR_ALLOW_ZERO_STOCK === 'Y' || !e.target || e.target.name !== 'rq_qty[]') return;
            const row = e.target.closest('tr');
            const stockInput = row.querySelector('input[name="stock[]"]');
            const stockQty = parseFloat(stockInput ? stockInput.value : 0) || 0;
            if ((parseFloat(e.target.value) || 0) > stockQty) {
                alert('Entered Qty is more than the Stock..');
                e.target.value = Math.max(stockQty, 0).toFixed(2);
                copyQty(e.target);
            }
        });
    </script>

    <!-- Item Search Modal -->
    <div id="itemSearchModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Item</h4>
                <button type="button" class="erp-search-close" onclick="closeItemSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
            <div class="erp-search-bar">
                <div class="erp-search-input">
                    <i class="fa fa-search"></i>
                    <input type="text" id="itemSearchInput" autocomplete="off"
                        placeholder="Search by Item Code, Name, or OEM Code..." onkeyup="searchItems(this.value)">
                </div>
            </div>
            <div class="erp-search-body">
                <table class="erp-search-table" id="itemSearchResults">
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>OEM Code</th>
                            <th>Item Name</th>
                            <th style="width: 100px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Job Search Modal -->
    <div id="jobSearchModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Job</h4>
                <button type="button" class="erp-search-close" onclick="closeJobSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
            <div class="erp-search-bar">
                <div class="erp-search-input">
                    <i class="fa fa-search"></i>
                    <input type="text" id="jobSearchInput" autocomplete="off"
                        placeholder="Search by Job No or Account Name..." onkeyup="searchJobs(this.value)">
                </div>
            </div>
            <div class="erp-search-body">
                <table class="erp-search-table" id="jobSearchResults">
                    <thead>
                        <tr>
                            <th>Job No</th>
                            <th>Job Date</th>
                            <th>Account Name</th>
                            <th style="width: 100px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Vehicle Search Modal -->
    <div id="vehicleSearchModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Vehicle</h4>
                <button type="button" class="erp-search-close" onclick="closeVehicleSearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
            <div class="erp-search-bar">
                <div class="erp-search-input">
                    <i class="fa fa-search"></i>
                    <input type="text" id="vehicleSearchInput" autocomplete="off"
                        placeholder="Search by Vehicle No, Plate No, or Plate Code..." onkeyup="searchVehicles(this.value)">
                </div>
            </div>
            <div class="erp-search-body">
                <table class="erp-search-table" id="vehicleSearchResults">
                    <thead>
                        <tr>
                            <th>Vehicle No</th>
                            <th>Plate No</th>
                            <th>Plate Code</th>
                            <th style="width: 100px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Activity Search Modal -->
    <div id="activitySearchModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog lg" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Activity</h4>
                <button type="button" class="erp-search-close" onclick="closeActivitySearch()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>
            <div class="erp-search-bar">
                <div class="erp-search-input">
                    <i class="fa fa-search"></i>
                    <input type="text" id="activitySearchInput" autocomplete="off"
                        placeholder="Search by Activity Code or Name..." onkeyup="searchActivities(this.value)">
                </div>
            </div>
            <div class="erp-search-body">
                <table class="erp-search-table" id="activitySearchResults">
                    <thead>
                        <tr>
                            <th>Activity Code</th>
                            <th>Activity Name</th>
                            <th style="width: 100px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Job BOM Modal -->
    <div id="jobBomModal" class="popup-overlay erp-search" style="display:none;">
        <div class="erp-search-dialog xl" role="dialog" aria-modal="true">
            <div class="erp-search-head">
                <h4 class="erp-search-title"><i class="fa fa-search"></i>Select Job Bill of Material</h4>
                <button type="button" class="erp-search-close" onclick="closeJobBomModal()" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
            </div>

            <div class="erp-search-panes">
                <!-- Left Side: Open Jobs -->
                <div class="erp-search-pane">
                    <div class="erp-search-pane-head">
                        <div class="erp-search-input">
                            <i class="fa fa-search"></i>
                            <input type="text" id="jobBomSearchInput" autocomplete="off" placeholder="Search Jobs..."
                                onkeyup="searchJobBomJobs(this.value)">
                        </div>
                    </div>
                    <div class="erp-search-body">
                        <table class="erp-search-table" id="jobBomJobsTable">
                            <thead>
                                <tr>
                                    <th>Job No</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody style="cursor: pointer;"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Right Side: BOMs -->
                <div class="erp-search-pane wide">
                    <div class="erp-search-pane-head">
                        <div class="erp-search-pane-row">
                            <h6 class="erp-search-pane-title" id="selectedBomJobTitle">Select a Job</h6>
                            <button type="button" class="erp-search-btn primary sm" onclick="addSelectedBoms()">Add Selected
                                items</button>
                        </div>
                        <div class="erp-search-input">
                            <i class="fa fa-search"></i>
                            <input type="text" id="bomItemSearchInput" autocomplete="off"
                                placeholder="Search by item code or item description..."
                                oninput="filterBomItems(this.value)">
                        </div>
                    </div>
                    <div class="erp-search-body">
                        <table class="erp-search-table" id="jobBomItemsTable">
                            <thead>
                                <tr id="jobBomItemsHeaderRow">
                                    <th style="min-width: 50px;">No</th>
                                    <th id="bomActivityHeader" style="min-width: 150px;">Activity</th>
                                    <th style="min-width: 130px;">Item Code</th>
                                    <th style="min-width: 250px;">Item Description</th>
                                    <th style="min-width: 80px;">Stock</th>
                                    <th style="min-width: 80px;">Qty</th>
                                    <th style="min-width: 60px;">Unit</th>
                                    <th style="text-align:center; min-width: 40px;"><input type="checkbox"
                                            id="selectAllBoms" onchange="toggleAllBoms(this)"></th>
                                    <th style="min-width: 80px;">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="9" class="text-center text-muted p-3">Select a job from the left panel
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentItemInput = null;

        function openItemSearch(inputElement) {
            if (!isEditActive) return;
            currentItemInput = inputElement;
            const modal = document.getElementById('itemSearchModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            const input = document.getElementById('itemSearchInput');
            input.value = '';
            input.focus();
            searchItems('');
        }

        function closeItemSearch() {
            document.getElementById('itemSearchModal').style.display = 'none';
            document.body.style.overflow = '';
            currentItemInput = null;
        }

        let searchTimeout;
        function searchItems(query) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                fetch('../../search_item.php?term=' + encodeURIComponent(query))
                    .then(response => {
                        if (!response.ok) throw new Error('Server returned ' + response.status);
                        return response.json();
                    })
                    .then(data => {
                        const tbody = document.querySelector('#itemSearchResults tbody');
                        tbody.innerHTML = '';

                        // Check for error object
                        if (data && data.error) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3 text-danger">Error: ' + data.error + '</td></tr>';
                            return;
                        }

                        if (!Array.isArray(data) || data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3">No items found</td></tr>';
                            return;
                        }

                        // Check for error in first item
                        if (data.length > 0 && data[0].error) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3 text-danger">Error: ' + data[0].error + '</td></tr>';
                            return;
                        }

                        data.forEach(item => {
                            const tr = document.createElement('tr');
                            // Prepare values for onClick, escaping quotes
                            const code = item.item_code ? item.item_code.replace(/'/g, "\\'") : '';
                            const name = item.item_name ? item.item_name.replace(/'/g, "\\'") : '';
                            const oem = item.oem_code || '';
                            const unit = item.unit ? item.unit.replace(/'/g, "\\'") : '';
                            const stock = item.stock || '0';

                            tr.innerHTML = `
                                <td>${item.item_code}</td>
                                <td>${oem}</td>
                                <td>${item.item_name}</td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-primary btn-sm" 
                                        onclick="selectItem('${code}', '${name}', '${unit}', '${stock}')">
                                        Select
                                    </button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(err => {
                        console.error('Error fetching items:', err);
                        const tbody = document.querySelector('#itemSearchResults tbody');
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                    });
            }, 300); // Debounce
        }

        function selectItem(code, name, unit, stock) {
            if (currentItemInput) {
                const row = currentItemInput.closest('tr');
                // item_code input
                currentItemInput.value = code;

                // other inputs in the same row
                const descInput = row.querySelector('input[name="item_desc[]"]');
                if (descInput) descInput.value = name;

                const unitInput = row.querySelector('select[name="unit[]"]');
                if (unitInput) unitInput.value = unit;

                const stockInput = row.querySelector('input[name="stock[]"]');
                if (stockInput) {
                    // Ensure stock is treated as a number and formatted if needed, currently raw string is fine
                    stockInput.value = stock !== undefined && stock !== null ? parseFloat(stock).toFixed(2) : '0.00';
                }
            }
            closeItemSearch();
        }



        // --- Activity Search Logic ---
        let currentActivityInput = null;

        function openActivitySearch(inputElement) {
            if (!isEditActive) return;
            currentActivityInput = inputElement;
            const modal = document.getElementById('activitySearchModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            const input = document.getElementById('activitySearchInput');
            input.value = '';
            input.focus();
            searchActivities('');
        }

        function closeActivitySearch() {
            document.getElementById('activitySearchModal').style.display = 'none';
            document.body.style.overflow = '';
            currentActivityInput = null;
        }

        let activitySearchTimeout;
        function searchActivities(query) {
            clearTimeout(activitySearchTimeout);
            activitySearchTimeout = setTimeout(() => {
                fetch('../../search_activity.php?term=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        const tbody = document.querySelector('#activitySearchResults tbody');
                        tbody.innerHTML = '';
                        if (data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="3" class="text-center p-3">No activities found</td></tr>';
                            return;
                        }

                        if (data.length > 0 && data[0].error) {
                            tbody.innerHTML = '<tr><td colspan="3" class="text-center p-3 text-danger">Error: ' + data[0].error + '</td></tr>';
                            return;
                        }

                        data.forEach(act => {
                            const tr = document.createElement('tr');
                            const ac_code = act.ac_subcode ? act.ac_subcode.replace(/'/g, "\\'") : '';
                            const ac_name = act.ac_name ? act.ac_name.replace(/'/g, "\\'") : '';

                            tr.innerHTML = `
                                <td>${act.ac_subcode}</td>
                                <td>${act.ac_name}</td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="selectActivity('${ac_code}', '${ac_name}')">Select</button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(err => {
                        console.error('Error fetching activities:', err);
                        const tbody = document.querySelector('#activitySearchResults tbody');
                        tbody.innerHTML = '<tr><td colspan="3" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                    });
            }, 300);
        }

        function selectActivity(ac_code, ac_name) {
            if (currentActivityInput) {
                currentActivityInput.value = ac_name || ac_code;
                const hiddenInput = currentActivityInput.previousElementSibling;
                if (hiddenInput && hiddenInput.name === 'ac_subcode[]') {
                    hiddenInput.value = ac_code;
                }
            }
            closeActivitySearch();
        }



        // --- Vehicle Search Logic ---
        let currentVehicleInput = null;

        function openVehicleSearch(inputElement) {
            if (!isEditActive) return;
            currentVehicleInput = inputElement;
            const modal = document.getElementById('vehicleSearchModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            const input = document.getElementById('vehicleSearchInput');
            input.value = '';
            input.focus();
            searchVehicles('');
        }

        function closeVehicleSearch() {
            document.getElementById('vehicleSearchModal').style.display = 'none';
            document.body.style.overflow = '';
            currentVehicleInput = null;
        }

        let vehicleSearchTimeout;
        function searchVehicles(query) {
            clearTimeout(vehicleSearchTimeout);
            vehicleSearchTimeout = setTimeout(() => {
                fetch('../../search_vehicle.php?term=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        const tbody = document.querySelector('#vehicleSearchResults tbody');
                        tbody.innerHTML = '';
                        if (data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3">No vehicles found</td></tr>';
                            return;
                        }

                        if (data.length > 0 && data[0].error) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3 text-danger">Error: ' + data[0].error + '</td></tr>';
                            return;
                        }

                        data.forEach(vehicle => {
                            const tr = document.createElement('tr');
                            const v_no = vehicle.vehicle_no ? vehicle.vehicle_no.replace(/'/g, "\\'") : '';
                            const p_no = vehicle.plate_no || '';
                            const p_code = vehicle.plate_code || '';

                            tr.innerHTML = `
                                <td>${vehicle.vehicle_no}</td>
                                <td>${p_no}</td>
                                <td>${p_code}</td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-primary btn-sm" 
                                        onclick="selectVehicle('${v_no}')">
                                        Select
                                    </button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(err => {
                        console.error('Error fetching vehicles:', err);
                        const tbody = document.querySelector('#vehicleSearchResults tbody');
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                    });
            }, 300); // Debounce
        }

        function selectVehicle(vehicle_no) {
            if (currentVehicleInput) {
                currentVehicleInput.value = vehicle_no;
            }
            closeVehicleSearch();
        }



        // --- Job Search Logic ---
        let currentJobInput = null;

        function openJobSearch(inputElement) {
            if (!isEditActive) return;
            currentJobInput = inputElement;
            const modal = document.getElementById('jobSearchModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            const input = document.getElementById('jobSearchInput');
            input.value = '';
            input.focus();
            searchJobs('');
        }

        function closeJobSearch() {
            document.getElementById('jobSearchModal').style.display = 'none';
            document.body.style.overflow = '';
            currentJobInput = null;
        }

        let jobSearchTimeout;
        function searchJobs(query) {
            clearTimeout(jobSearchTimeout);
            jobSearchTimeout = setTimeout(() => {
                fetch('../../search_job.php?term=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        const tbody = document.querySelector('#jobSearchResults tbody');
                        tbody.innerHTML = '';
                        if (data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3">No jobs found</td></tr>';
                            return;
                        }

                        if (data.length > 0 && data[0].error) {
                            tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3 text-danger">Error: ' + data[0].error + '</td></tr>';
                            return;
                        }

                        data.forEach(job => {
                            const tr = document.createElement('tr');
                            const job_no = job.doc_no ? job.doc_no.replace(/'/g, "\\'") : '';
                            const job_dt = job.doc_dt || '';
                            const ac_name = job.ac_name ? job.ac_name.replace(/'/g, "\\'") : '';

                            tr.innerHTML = `
                                <td>${job.doc_no}</td>
                                <td>${job_dt}</td>
                                <td>${job.ac_name}</td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-primary btn-sm" 
                                        onclick="selectJob('${job_no}')">
                                        Select
                                    </button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(err => {
                        console.error('Error fetching jobs:', err);
                        const tbody = document.querySelector('#jobSearchResults tbody');
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                    });
            }, 300); // Debounce
        }

        function selectJob(job_no) {
            if (currentJobInput) {
                currentJobInput.value = job_no;
            }
            closeJobSearch();
        }



        // --- Job BOM Selection Logic ---
        let currentBomJobNo = '';
        let currentBomItems = [];
        let currentBomWithActivity = false;

        function openJobBomModal(withActivity) {
            currentBomWithActivity = withActivity;
            document.getElementById('jobBomModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            document.getElementById('jobBomSearchInput').value = '';
            document.getElementById('selectedBomJobTitle').innerText = 'Select a Job';
            const bomSearch = document.getElementById('bomItemSearchInput');
            if (bomSearch) bomSearch.value = '';

            const tableHeaders = document.getElementById('jobBomItemsHeaderRow');
            const activityHeader = document.getElementById('bomActivityHeader');
            const colspan = withActivity ? 9 : 8;

            if (withActivity) {
                activityHeader.style.display = '';
            } else {
                activityHeader.style.display = 'none';
            }

            document.querySelector('#jobBomItemsTable tbody').innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted p-3">Select a job from the left panel</td></tr>`;
            currentBomJobNo = '';
            currentBomItems = [];
            searchJobBomJobs('');
        }

        function closeJobBomModal() {
            document.getElementById('jobBomModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        let jobBomSearchTimeout;
        function searchJobBomJobs(query) {
            clearTimeout(jobBomSearchTimeout);
            jobBomSearchTimeout = setTimeout(() => {
                fetch('../../get_open_jobs.php?term=' + encodeURIComponent(query))
                    .then(response => {
                        if (!response.ok) throw new Error('Server returned ' + response.status);
                        return response.json();
                    })
                    .then(data => {
                        const tbody = document.querySelector('#jobBomJobsTable tbody');
                        tbody.innerHTML = '';

                        // Check for error object
                        if (data && data.error) {
                            tbody.innerHTML = '<tr><td colspan="2" class="text-center p-3 text-danger">Error: ' + data.error + '</td></tr>';
                            return;
                        }

                        if (!Array.isArray(data) || data.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="2" class="text-center p-3">No open jobs found</td></tr>';
                            return;
                        }

                        data.forEach(job => {
                            const tr = document.createElement('tr');
                            tr.onclick = () => selectBomJob(job.docno);
                            tr.innerHTML = `
                                <td>${job.docno}</td>
                                <td>${job.doc_dt}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    })
                    .catch(err => {
                        console.error('Error fetching jobs:', err);
                        const tbody = document.querySelector('#jobBomJobsTable tbody');
                        tbody.innerHTML = '<tr><td colspan="2" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                    });
            }, 300);
        }

        function filterBomItems(query) {
            const q = query.trim().toLowerCase();
            const rows = document.querySelectorAll('#jobBomItemsTable tbody tr');
            rows.forEach(tr => {
                if (tr.cells.length <= 1) return; // skip placeholder rows
                // item code and item description cells depend on withActivity
                const cellCount = tr.cells.length;
                // With activity: No|Activity|ItemCode|ItemDesc|...
                // Without activity: No|ItemCode|ItemDesc|...
                const codeIdx  = currentBomWithActivity ? 2 : 1;
                const descIdx  = currentBomWithActivity ? 3 : 2;
                const codeText = (tr.cells[codeIdx]  ? tr.cells[codeIdx].innerText  : '').toLowerCase();
                const descText = (tr.cells[descIdx]  ? tr.cells[descIdx].innerText  : '').toLowerCase();
                tr.style.display = (!q || codeText.includes(q) || descText.includes(q)) ? '' : 'none';
            });
        }

        function selectBomJob(jobNo) {
            currentBomJobNo = jobNo;
            document.getElementById('selectedBomJobTitle').innerText = jobNo;
            const bomSearch = document.getElementById('bomItemSearchInput');
            if (bomSearch) bomSearch.value = '';

            const rows = document.querySelectorAll('#jobBomJobsTable tbody tr');
            rows.forEach(r => {
                if (r.cells[0].innerText === jobNo) {
                    r.style.backgroundColor = 'var(--primary)';
                    r.style.color = 'white';
                } else {
                    r.style.backgroundColor = '';
                    r.style.color = '';
                }
            });

            const colspan = currentBomWithActivity ? 8 : 7;
            document.querySelector('#jobBomItemsTable tbody').innerHTML = `<tr><td colspan="${colspan}" class="text-center p-3">Loading...</td></tr>`;

            // Pass with_activity flag to backend
            fetch(`../../get_job_boms.php?doc_no=${encodeURIComponent(jobNo)}&with_activity=${currentBomWithActivity ? 1 : 0}`)
                .then(response => response.json())
                .then(data => {
                    const tbody = document.querySelector('#jobBomItemsTable tbody');
                    tbody.innerHTML = '';
                    currentBomItems = data;

                    if (data.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center p-3">No BOM items found for this job</td></tr>`;
                        return;
                    }

                    document.getElementById('selectAllBoms').checked = false;

                    data.forEach((item, index) => {
                        const tr = document.createElement('tr');

                        let activityCell = '';
                        if (currentBomWithActivity) {
                            activityCell = `<td>${item.acname}</td>`;
                        }

                        tr.innerHTML = `
                            <td>${item.itemno}</td>
                            ${activityCell}
                            <td>${item.itemcode}</td>
                            <td>${item.itemname}</td>
                            <td>${item.stockqty.toFixed(2)}</td>
                            <td>${item.txnqty.toFixed(2)}</td>
                            <td>${item.unit}</td>
                            <td class="text-center"><input type="checkbox" class="bom-item-chk" value="${index}"></td>
                            <td style="padding: 4px;"><input type="number" class="form-input-custom bom-item-qty text-center" value="${item.txnqty.toFixed(2)}" step="0.01" style="width: 80px; padding: 2px;"></td>
                        `;
                        tbody.appendChild(tr);
                    });
                })
                .catch(err => {
                    console.error('Error fetching BOM details:', err);
                    const tbody = document.querySelector('#jobBomItemsTable tbody');
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center p-3 text-danger">Network or Parsing Error</td></tr>';
                });
        }

        function toggleAllBoms(source) {
            const checkboxes = document.querySelectorAll('.bom-item-chk');
            checkboxes.forEach(chk => chk.checked = source.checked);
        }

        function addSelectedBoms() {
            const checkboxes = document.querySelectorAll('.bom-item-chk:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one item');
                return;
            }

            if (MR_ALLOW_ZERO_STOCK !== 'Y') {
                const overStock = [];
                checkboxes.forEach(chk => {
                    const item = currentBomItems[chk.value];
                    const qtyInput = chk.closest('tr').querySelector('.bom-item-qty');
                    const qty = qtyInput ? (parseFloat(qtyInput.value) || 0) : (parseFloat(item.qty) || 0);
                    if (qty > (parseFloat(item.stockqty) || 0)) overStock.push(item.itemcode);
                });
                if (overStock.length) {
                    alert('Entered Qty is more than the Stock..\n' + overStock.join(', '));
                    return;
                }
            }

            const today = new Date().toISOString().split('T')[0];

            checkboxes.forEach(chk => {
                const index = chk.value;
                const item = currentBomItems[index];

                const tr = chk.closest('tr');
                const reqQtyInput = tr.querySelector('.bom-item-qty');
                const reqQty = reqQtyInput ? parseFloat(reqQtyInput.value) : item.qty;

                addLine();

                const tbody = document.getElementById('itemsBody');
                const newRow = tbody.rows[tbody.rows.length - 1];

                const acSubcodeInput = newRow.querySelector('input[name="ac_subcode[]"]');
                if (acSubcodeInput) {
                    acSubcodeInput.value = currentBomWithActivity ? (item.acsubcode || '') : '';
                }

                const acNameInput = newRow.querySelector('input[name="ac_name[]"]');
                if (acNameInput) {
                    acNameInput.value = currentBomWithActivity ? (item.acname || item.acsubcode || '') : '';
                }

                const itemCodeInput = newRow.querySelector('input[name="item_code[]"]');
                if (itemCodeInput) itemCodeInput.value = item.itemcode;

                const descInput = newRow.querySelector('input[name="item_desc[]"]');
                if (descInput) descInput.value = item.itemname;

                const rqQtyInputCol = newRow.querySelector('input[name="rq_qty[]"]');
                if (rqQtyInputCol) {
                    rqQtyInputCol.value = reqQty.toFixed(2);
                    copyQty(rqQtyInputCol);
                }

                const stockInputCol = newRow.querySelector('input[name="stock[]"]');
                if (stockInputCol) stockInputCol.value = parseFloat(item.stockqty).toFixed(2);

                const unitInput = newRow.querySelector('select[name="unit[]"]');
                if (unitInput) {
                    populateUnitSelect(unitInput, item.unit);
                }

                const expDtInput = newRow.querySelector('input[name="expected_dt[]"]');
                if (expDtInput) expDtInput.value = '';

                const jobNoInput = newRow.querySelector('input[name="job_no[]"]');
                if (jobNoInput) jobNoInput.value = currentBomJobNo;

                const lineNoInput = newRow.querySelector('input[name="line_no[]"]');
                if (lineNoInput) lineNoInput.value = item.itemno;
            });

            closeJobBomModal();

            const tbody = document.getElementById('itemsBody');
            if (tbody.rows.length > 1) {
                const firstRow = tbody.rows[0];
                const firstRowCode = firstRow.querySelector('input[name="item_code[]"]');
                if (firstRowCode && !firstRowCode.value) {
                    firstRow.remove();
                }
            }

            // Always reindex after adding BOM items
            reindexRows();

            // Re-bind onclick events to dynamically added input fields (Item search, Job search, Vehicle Search)
            const newInputsCodes = document.querySelectorAll('input[name="item_code[]"]');
            newInputsCodes.forEach(input => {
                input.onclick = function () { openItemSearch(this); };
            });

            const newInputsJobs = document.querySelectorAll('input[name="job_no[]"]');
            newInputsJobs.forEach(input => {
                input.onclick = function () { openJobSearch(this); };
            });

            const newInputsVehicles = document.querySelectorAll('input[name="vehicle_no[]"]');
            newInputsVehicles.forEach(input => {
                input.onclick = function () { openVehicleSearch(this); };
            });

            const newInputsActivities = document.querySelectorAll('input[name="ac_name[]"]');
            newInputsActivities.forEach(input => {
                input.onclick = function () { openActivitySearch(this); };
            });
        }

        document.getElementById('jobBomSearchInput').addEventListener('keyup', function () {
            searchJobBomJobs(this.value);
        });

        document.getElementById('selectAllBoms').addEventListener('change', function () {
            toggleAllBoms(this);
        });

        // document.getElementById('addSelectedBomItems').addEventListener('click', addSelectedBoms);

        // Called by the header's enableEditMode() after the edit password is verified
        function mrDetailEnableEdit() {
            const deleteButtons = document.querySelectorAll('button[onclick^="deleteRow"]');
            deleteButtons.forEach(btn => btn.removeAttribute('disabled'));

            document.getElementById('addLineBtn').removeAttribute('disabled');
            document.getElementById('bomBtn').removeAttribute('disabled');

            // Unlock detail line fields — skip rows where approved = Y
            if (DOC_APPROVED !== 'Y') {
                const tbody = document.getElementById('itemsBody');
                const rows = tbody.rows;
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i];

                    // Skip approved rows — keep them fully readonly
                    if (row.dataset.rowApproved === 'Y') continue;

                    // Unlock typed inputs
                    ['input[name="item_desc[]"]', 'input[name="rq_qty[]"]',
                     'input[name="unit[]"]', 'input[name="expected_dt[]"]'].forEach(sel => {
                        const el = row.querySelector(sel);
                        if (el) el.removeAttribute('readonly');
                    });

                    // Add search onclick handlers to lookup inputs
                    const acInput = row.querySelector('input[name="ac_name[]"]');
                    if (acInput) { acInput.onclick = function() { openActivitySearch(this); }; acInput.placeholder = 'Click to select'; }

                    const itemCodeInput = row.querySelector('input[name="item_code[]"]');
                    if (itemCodeInput) { itemCodeInput.onclick = function() { openItemSearch(this); }; itemCodeInput.placeholder = 'Click to select'; }

                    const jobInput = row.querySelector('input[name="job_no[]"]');
                    if (jobInput) { jobInput.onclick = function() { openJobSearch(this); }; jobInput.placeholder = 'Click to select'; }

                    const vehicleInput = row.querySelector('input[name="vehicle_no[]"]');
                    if (vehicleInput) { vehicleInput.onclick = function() { openVehicleSearch(this); }; vehicleInput.placeholder = 'Click to select'; }

                    // Replace remark span with clickable link
                    const remarkTd = row.querySelector('td[data-label="Remark"]');
                    if (remarkTd) {
                        const remarkInput = remarkTd.querySelector('input[name="item_remark[]"]');
                        if (remarkInput) {
                            remarkTd.innerHTML = remarkInput.outerHTML +
                                '<a href="javascript:void(0)" onclick="openRemarkPopup(this)" ' +
                                'style="color: var(--primary); font-weight: 500; font-size: 0.9rem;">more..</a>';
                        }
                    }
                }
            }
        }

        <?php if (!$is_view_mode && $insert_key_lock !== 'Y'): ?>
        if (!document.getElementById('itemsBody').rows.length) addLine();
        <?php endif; ?>
    </script>
