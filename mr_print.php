<?php
@session_start();
require('db.php');
require('auth.php');

$company_code = $_SESSION["company_code"] ?? '';
$branch_code  = $_SESSION["branch_code"]  ?? '';

$doc_no = isset($_GET['doc_no']) ? trim($_GET['doc_no']) : '';
if ($doc_no === '') {
    die('<p style="font-family:sans-serif;padding:20px;">No document specified.</p>');
}

// ── Header ────────────────────────────────────────────────────────────────────
$stmt_h = $con->prepare("SELECT * FROM material_request_h WHERE doc_no = ? AND company_code = ? AND branch_code = ?");
$stmt_h->execute([$doc_no, $company_code, $branch_code]);
$h = $stmt_h->fetch(PDO::FETCH_ASSOC);
if (!$h) {
    die('<p style="font-family:sans-serif;padding:20px;">Document not found.</p>');
}

// ── Detail lines ──────────────────────────────────────────────────────────────
$stmt_d = $con->prepare("
    SELECT d.doc_no, d.serial_no, d.item_no, d.ac_subcode, d.item_code, d.item_description,
           d.stock_qty, d.request_qty, d.trans_qty, d.item_unit, d.trans_unit,
           d.expected_date, d.job_no, d.vehicle_no, d.remark, d.upp, d.approved,
           a.ac_name
    FROM   material_request_d d
    LEFT   JOIN ac_subledger a ON a.ac_subcode = d.ac_subcode AND a.company_code = d.company_code
    WHERE  d.doc_no = ? AND d.company_code = ? AND d.branch_code = ?
    ORDER  BY d.item_no ASC
");
$stmt_d->execute([$doc_no, $company_code, $branch_code]);
$details = $stmt_d->fetchAll(PDO::FETCH_ASSOC);

// ── Company name ──────────────────────────────────────────────────────────────
$stmt_c = $con->prepare("SELECT company_name FROM company_master WHERE company_code = ?");
$stmt_c->execute([$company_code]);
$crow = $stmt_c->fetch(PDO::FETCH_ASSOC);
$company_name = $crow ? strtoupper(trim($crow['company_name'])) : '';

// ── Helper: first job_no from detail lines ────────────────────────────────────
$first_job = '';
foreach ($details as $d) {
    if (!empty(trim($d['job_no'] ?? ''))) { $first_job = trim($d['job_no']); break; }
}

// ── Format date ───────────────────────────────────────────────────────────────
function fmt_date($val) {
    if (!$val) return '';
    $v = trim($val);
    if (strpos($v, ' ') !== false) $v = explode(' ', $v)[0];
    try { return (new DateTime($v))->format('d/m/Y'); } catch (Exception $e) { return $v; }
}

// ── Field helpers ─────────────────────────────────────────────────────────────
$pDoc      = strtoupper(trim($h['doc_no']      ?? ''));
$pDate     = fmt_date($h['doc_dt']              ?? '');
$pNarr     = strtoupper(trim($h['narration']    ?? ''));
$pSiteLoc  = strtoupper(trim($h['site_location']?? ''));
$pDept     = strtoupper(trim($h['dept_code']    ?? ''));
$pReqBy    = strtoupper(trim($h['request_by']   ?? ''));
$pSiteNo   = strtoupper(trim($h['site_no']      ?? ''));

// Total qty
$total_qty = 0;
foreach ($details as $d) {
    $total_qty += (float)($d['request_qty'] ?? 0);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Material Request - <?php echo htmlspecialchars($pDoc); ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    color: #000;
    background: #e5e7eb;
  }

  .page {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 0;
    display: flex;
    flex-direction: column;
    background: #fff;
    box-shadow: 0 2px 12px rgba(0,0,0,.18);
  }

  /* Letterhead images */
  .lh-header {
    width: 100%;
    display: block;
  }

  .lh-footer {
    width: 100%;
    display: block;
    margin-top: auto;
  }

  /* Content area between header and footer */
  .page-content {
    padding: 4mm 14mm 8mm 14mm;
    flex: 1;
  }

  /* ── Title ── */
  .print-title {
    text-align: center;
    font-size: 14pt;
    font-weight: bold;
    margin-bottom: 8px;
  }

  .print-divider {
    border-top: 1px solid #000;
    margin-bottom: 8px;
  }

  /* ── Info header ── */
  .info-row {
    display: flex;
    gap: 8px;
    margin-bottom: 6px;
  }

  .info-box {
    border: 1px solid #000;
    border-radius: 4px;
    padding: 5px 9px;
  }

  .info-box-left  { flex: 1.7; }
  .info-box-right { flex: 1; }

  .info-line {
    display: flex;
    margin-bottom: 2px;
    font-size: 9.5pt;
    line-height: 1.4;
  }

  .info-label  { min-width: 115px; }
  .info-colon  { margin: 0 5px; }
  .info-value  { font-weight: normal; }

  /* ── Detail table wrapper ── */
  .table-wrap {
    margin-top: 6px;
  }

  .detail-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5pt;
  }

  .detail-table th,
  .detail-table td {
    border: 1px solid #000;
    padding: 2px 4px;
    vertical-align: middle;
    line-height: 1.3;
  }

  .detail-table thead th {
    background: #fff;
    font-weight: bold;
    text-align: center;
    font-size: 10pt;
  }

  .detail-table .col-no       { width: 30px;  text-align: center; }
  .detail-table .col-activity { width: 130px; }
  .detail-table .col-code     { width: 80px;  }
  .detail-table .col-desc     { }
  .detail-table .col-qty      { width: 50px;  text-align: center; }
  .detail-table .col-unit     { width: 50px;  text-align: center; }
  .detail-table .col-remark   { width: 110px; }

  .total-row td {
    font-weight: bold;
    text-align: right;
    padding: 4px 6px;
  }
  .total-row .total-label { text-align: right; }
  .total-row .total-val   { text-align: center; }

  /* ── Spacer removed — signatures are absolutely positioned ── */

  /* ── Signatures ── */
  .sig-area {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    font-size: 9.5pt;
    margin-top: 14mm;
    padding-top: 4px;
  }

  .sig-left, .sig-right {
    display: flex;
    flex-direction: column;
  }

  .sig-right { text-align: right; }

  .sig-line {
    border-top: 1px solid #000;
    width: 160px;
    margin-bottom: 4px;
  }

  .sig-right .sig-line { margin-left: auto; }

  /* ── Print rules ── */
  @media print {
    body  { background: #fff; }
    .page {
      margin: 0;
      width: 210mm; min-height: 297mm;
      box-shadow: none;
    }
    .page-content { padding: 3mm 12mm 6mm 12mm; }
    .no-print   { display: none !important; }
    @page { size: A4 portrait; margin: 0; }
  }

  /* ── Print button bar ── */
  .print-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    background: #1e40af;
    color: #fff;
    padding: 8px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    z-index: 999;
    font-family: Arial, sans-serif;
    font-size: 13px;
  }

  .print-bar button        { background: #fff; color: #1e40af; border: none; border-radius: 6px; padding: 5px 18px; font-weight: 700; font-size: 13px; cursor: pointer; }
  .print-bar button:hover  { background: #dbeafe; }
  .print-bar .btn-pdf      { background: #dc2626; color: #fff; }
  .print-bar .btn-pdf:hover{ background: #b91c1c; }
  .print-bar .btn-pdf:disabled { background: #9ca3af; cursor: not-allowed; }

  /* Screen: push content below toolbar */
  body.has-bar { padding-top: 48px; }
</style>
<!-- jsPDF + html2canvas for direct PDF download -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body class="has-bar">

<!-- Print / Close bar (hidden when printing) -->
<div class="print-bar no-print">
  <span style="font-weight:700;letter-spacing:1px;">MATERIAL REQUEST PREVIEW</span>
  <button onclick="window.print()">&#128424; Print</button>
  <button class="btn-pdf" onclick="downloadPdf()">&#128196; PDF</button>
  <button onclick="window.close()">&#10005; Close</button>
</div>

<div class="page">

  <!-- Letterhead Header -->
  <img src="image/header.jpg" class="lh-header" alt="letterhead header">

  <div class="page-content">

  <!-- Title -->
  <div class="print-title">Material Request Form</div>
  <div class="print-divider"></div>

  <!-- Info header -->
  <div style="margin-top:6px;">
    <div class="info-row">
    <!-- Left box -->
    <div class="info-box info-box-left">
      <div class="info-line">
        <span class="info-label">Customer</span>
        <span class="info-colon">:</span>
        <span class="info-value"><?php echo htmlspecialchars($pSiteNo); ?></span>
      </div>
      <div class="info-line">
        <span class="info-label">Project</span>
        <span class="info-colon">:</span>
        <span class="info-value"><?php echo htmlspecialchars($pNarr); ?></span>
      </div>
      <div class="info-line">
        <span class="info-label">Delivery Location</span>
        <span class="info-colon">:</span>
        <span class="info-value"><?php echo htmlspecialchars($pSiteLoc); ?></span>
      </div>
      <div class="info-line">
        <span class="info-label">Department</span>
        <span class="info-colon">:</span>
        <span class="info-value"><?php echo htmlspecialchars($pDept); ?></span>
      </div>
    </div>

    <!-- Right box -->
    <div class="info-box info-box-right">
      <div class="info-line">
        <span class="info-label">No</span>
        <span class="info-colon">:</span>
        <span class="info-value"><strong><?php echo htmlspecialchars($pDoc); ?></strong></span>
      </div>
      <div class="info-line">
        <span class="info-label">Date</span>
        <span class="info-colon">:</span>
        <span class="info-value"><?php echo htmlspecialchars($pDate); ?></span>
      </div>
      <div class="info-line">
        <span class="info-label">Job No</span>
        <span class="info-colon">:</span>
        <span class="info-value"><?php echo htmlspecialchars($first_job); ?></span>
      </div>
      <div class="info-line">
        <span class="info-label">Request by</span>
        <span class="info-colon">:</span>
        <span class="info-value"><?php echo htmlspecialchars($pReqBy); ?></span>
      </div>
    </div>
    </div>
  </div><!-- end info header wrapper -->

  <!-- Detail table -->
  <div class="table-wrap">
    <table class="detail-table">
    <thead>
      <tr>
        <th class="col-no">No</th>
        <th class="col-activity">Activity</th>
        <th class="col-code">Item Code</th>
        <th class="col-desc">Description</th>
        <th class="col-qty">Qty</th>
        <th class="col-unit">Unit</th>
        <th class="col-remark">Remark</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($details)): ?>
      <tr>
        <td colspan="7" style="text-align:center; padding:12px; color:#666;">No detail lines.</td>
      </tr>
      <?php else: ?>
      <?php foreach ($details as $i => $d):
        $ac_name   = strtoupper(trim($d['ac_name']         ?? ''));
        $item_code = strtoupper(trim($d['item_code']        ?? ''));
        $item_desc = strtoupper(trim($d['item_description'] ?? ''));
        $rq_qty    = (float)($d['request_qty'] ?? 0);
        $unit      = strtoupper(trim($d['item_unit']        ?? ''));
        $remark    = trim($d['remark']                      ?? '');
        $qty_disp  = ($rq_qty == (int)$rq_qty) ? (int)$rq_qty : number_format($rq_qty, 2);
      ?>
      <tr>
        <td class="col-no"><?php echo $i + 1; ?></td>
        <td class="col-activity"><?php echo htmlspecialchars($ac_name); ?></td>
        <td class="col-code"><?php echo htmlspecialchars($item_code); ?></td>
        <td class="col-desc"><?php echo htmlspecialchars($item_desc); ?></td>
        <td class="col-qty"><?php echo $qty_disp; ?></td>
        <td class="col-unit"><?php echo htmlspecialchars($unit); ?></td>
        <td class="col-remark"><?php echo htmlspecialchars($remark); ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="total-row">
        <td colspan="4" class="total-label" style="border:1px solid #000;">Total</td>
        <td class="col-qty total-val" style="border:1px solid #000;">
          <?php echo ($total_qty == (int)$total_qty) ? (int)$total_qty : number_format($total_qty, 2); ?>
        </td>
        <td style="border:1px solid #000;"></td>
        <td style="border:1px solid #000;"></td>
      </tr>
    </tfoot>
  </table>
  </div><!-- end table-wrap -->

  <!-- Signatures -->
  <div class="sig-area">
    <div class="sig-left">
      <div class="sig-line" style="width:170px;"></div>
      <div style="font-weight:600;"><?php echo htmlspecialchars($pReqBy); ?></div>
      <div style="margin-top:3px;font-size:9pt;color:#333;">Prepared by</div>
    </div>
    <div class="sig-right">
      <div class="sig-line" style="width:170px;margin-left:auto;"></div>
      <div>Authorised Signatory</div>
      <div style="margin-top:3px;">For &nbsp; <strong><?php echo htmlspecialchars($company_name); ?></strong></div>
    </div>
  </div>

  </div><!-- end page-content -->

  <!-- Letterhead Footer -->
  <img src="image/footer.jpg" class="lh-footer" alt="letterhead footer">

</div><!-- .page -->

<script>
function downloadPdf() {
    const btn = document.querySelector('.btn-pdf');
    btn.disabled = true;
    btn.textContent = 'Generating...';

    const page = document.querySelector('.page');

    html2canvas(page, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
        scrollX: 0,
        scrollY: -window.scrollY
    }).then(function(canvas) {
        const { jsPDF } = window.jspdf;
        const widthMm  = 210;
        const heightMm = widthMm * canvas.height / canvas.width;
        const pdf = new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: [widthMm, heightMm]
        });
        const imgData = canvas.toDataURL('image/jpeg', 1.0);
        pdf.addImage(imgData, 'JPEG', 0, 0, widthMm, heightMm);
        pdf.save('<?php echo addslashes($pDoc); ?>_Material_Request.pdf');
        btn.disabled = false;
        btn.innerHTML = '&#128196; PDF';
    }).catch(function(err) {
        console.error(err);
        btn.disabled = false;
        btn.innerHTML = '&#128196; PDF';
        alert('Failed to generate PDF. Please try again.');
    });
}
</script>
</body>
</html>
