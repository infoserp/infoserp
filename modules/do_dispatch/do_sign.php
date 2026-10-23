<?php
/**
 * Proof of Delivery — signature save handler
 * Runs only when this file is POSTed to directly (fetch from modal).
 * When included by do_dispatch.php the block below is skipped.
 */
if (
    basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'do_sign.php'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
) {
    ob_start();

    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('html_errors', '0');
    @ini_set('log_errors', '1');

    function dod_sign_json($payload, $http_code = 200) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($http_code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Reject blank / near-blank PNG signatures (click with no stroke).
     * Requires a small amount of non-transparent, non-white ink.
     */
    function dod_signature_png_has_ink($png_data) {
        if (!is_string($png_data) || $png_data === '' || !function_exists('imagecreatefromstring')) {
            return false;
        }

        $img = @imagecreatefromstring($png_data);
        if ($img === false) {
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);
            return false;
        }

        $ink = 0;
        $stepX = max(1, (int) floor($w / 200));
        $stepY = max(1, (int) floor($h / 120));

        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba & 0x7F000000) >> 24; /* GD: 0 opaque … 127 transparent */
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                if ($a > 110) {
                    continue;
                }
                if ($r < 245 || $g < 245 || $b < 245) {
                    $ink++;
                    if ($ink >= 8) {
                        imagedestroy($img);
                        return true;
                    }
                }
            }
        }

        imagedestroy($img);
        return false;
    }

    register_shutdown_function(function () {
        if (headers_sent()) {
            return;
        }
        $err = error_get_last();
        if (!$err) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatal, true)) {
            return;
        }
        dod_sign_json([
            'success' => false,
            'error'   => 'Server error: ' . $err['message'],
        ], 500);
    });

    try {
        require __DIR__ . '/../../db.php';

        if (empty($_SESSION['username'])) {
            dod_sign_json(['success' => false, 'error' => 'Session expired. Please log in again.'], 401);
        }

        $company_code = $_SESSION['company_code'] ?? '';
        $branch_code  = $_SESSION['branch_code'] ?? '';

        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            dod_sign_json(['success' => false, 'error' => 'Invalid request body.']);
        }

        $doc_no        = trim((string) ($data['doc_no'] ?? ''));
        $signature_b64 = (string) ($data['signature'] ?? '');
        $receiver_name    = trim((string) ($data['receiver_name'] ?? ''));
        $receiver_contact = trim((string) ($data['receiver_contact'] ?? ''));
        $receiver_latitude  = $data['receiver_latitude'] ?? null;
        $receiver_longitude = $data['receiver_longitude'] ?? null;
        $receiver_location_accuracy = $data['receiver_location_accuracy'] ?? null;

        if ($doc_no === '' || $signature_b64 === '') {
            dod_sign_json(['success' => false, 'error' => 'Missing required fields (doc_no / signature).']);
        }

        if ($receiver_name === '') {
            dod_sign_json(['success' => false, 'error' => 'Receiver name is required.']);
        }
        if ($receiver_contact === '') {
            dod_sign_json(['success' => false, 'error' => 'Receiver number is required.']);
        }

        if (!is_numeric($receiver_latitude) || !is_numeric($receiver_longitude)) {
            dod_sign_json(['success' => false, 'error' => 'Valid GPS location is required to save the receiver signature.']);
        }

        $receiver_latitude  = (float) $receiver_latitude;
        $receiver_longitude = (float) $receiver_longitude;

        if ($receiver_latitude < -90 || $receiver_latitude > 90
            || $receiver_longitude < -180 || $receiver_longitude > 180) {
            dod_sign_json(['success' => false, 'error' => 'Invalid GPS coordinates.']);
        }

        if ($receiver_location_accuracy !== null && $receiver_location_accuracy !== '') {
            if (!is_numeric($receiver_location_accuracy)) {
                dod_sign_json(['success' => false, 'error' => 'Invalid location accuracy value.']);
            }
            $receiver_location_accuracy = (float) $receiver_location_accuracy;
        } else {
            $receiver_location_accuracy = null;
        }

        /* Reject if this DO already has a signature */
        $chk = $con->prepare(
            "SELECT receiver_signature_path
             FROM   delivery_note_h
             WHERE  doc_no       = :doc_no
               AND  company_code = :company_code
               AND  branch_code  = :branch_code"
        );
        $chk->bindValue(':doc_no',       $doc_no);
        $chk->bindValue(':company_code', $company_code);
        $chk->bindValue(':branch_code',  $branch_code);
        $chk->execute();
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            dod_sign_json(['success' => false, 'error' => 'Delivery order not found.']);
        }
        if (!empty($existing['receiver_signature_path'])) {
            dod_sign_json(['success' => false, 'error' => 'This delivery order has already been signed.']);
        }

        /* Strip data-URI prefix */
        if (strpos($signature_b64, ',') !== false) {
            $parts         = explode(',', $signature_b64, 2);
            $signature_b64 = $parts[1];
        }

        $png_data = base64_decode($signature_b64, true);
        if ($png_data === false || strlen($png_data) < 8) {
            dod_sign_json(['success' => false, 'error' => 'Invalid signature image data.']);
        }

        if (!dod_signature_png_has_ink($png_data)) {
            dod_sign_json([
                'success' => false,
                'error'   => 'Please draw your signature before saving. A blank signature cannot be saved.',
            ]);
        }

        /* DLO/42853 → DLO_42853_signature.png */
        $safe_doc   = preg_replace('/[\/\\\\]+/', '_', $doc_no);
        $filename   = $safe_doc . '_signature.png';
        $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR;

        if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0777, true)) {
            dod_sign_json(['success' => false, 'error' => 'Could not create uploads directory.']);
        }

        $full_path = $upload_dir . $filename;
        $written   = @file_put_contents($full_path, $png_data);
        if ($written === false) {
            dod_sign_json([
                'success' => false,
                'error'   => 'Could not save signature file. Please ask administrator to grant write access on modules/do_dispatch/uploads/signatures for the IIS app pool (ApprovalApp).',
            ]);
        }

        $rel_path       = 'modules/do_dispatch/uploads/signatures/' . $filename;
        $signature_date = date('Y-m-d H:i:s');
        $location_captured_at = date('Y-m-d H:i:s');

        $sql = "UPDATE delivery_note_h
                SET    receiver_signature_path      = :sig_path,
                       signature_date               = :sig_date,
                       receiver_name                = :rcv_name,
                       receiver_contact             = :rcv_contact,
                       receiver_latitude            = :rcv_lat,
                       receiver_longitude           = :rcv_lng,
                       receiver_location_accuracy   = :rcv_acc,
                       receiver_location_address    = NULL,
                       receiver_location_captured_at = :rcv_loc_at
                WHERE  doc_no       = :doc_no
                  AND  company_code = :company_code
                  AND  branch_code  = :branch_code
                  AND  (receiver_signature_path IS NULL OR receiver_signature_path = '')";

        $stmt = $con->prepare($sql);
        $stmt->bindValue(':sig_path',     $rel_path);
        $stmt->bindValue(':sig_date',     $signature_date);
        $stmt->bindValue(':rcv_name',     $receiver_name);
        $stmt->bindValue(':rcv_contact',  $receiver_contact);
        $stmt->bindValue(':rcv_lat',      $receiver_latitude);
        $stmt->bindValue(':rcv_lng',      $receiver_longitude);
        if ($receiver_location_accuracy !== null) {
            $stmt->bindValue(':rcv_acc', $receiver_location_accuracy);
        } else {
            $stmt->bindValue(':rcv_acc', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':rcv_loc_at',   $location_captured_at);
        $stmt->bindValue(':doc_no',       $doc_no);
        $stmt->bindValue(':company_code', $company_code);
        $stmt->bindValue(':branch_code',  $branch_code);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            @unlink($full_path);
            dod_sign_json(['success' => false, 'error' => 'This delivery order has already been signed.']);
        }

        dod_sign_json([
            'success'       => true,
            'file'          => $filename,
            'path'          => $rel_path,
            'signed_at'     => $signature_date,
            'receiver_name'    => $receiver_name,
            'receiver_contact' => $receiver_contact,
            'receiver_latitude'  => $receiver_latitude,
            'receiver_longitude' => $receiver_longitude,
            'receiver_location_accuracy' => $receiver_location_accuracy,
            'receiver_location_captured_at' => $location_captured_at,
            'rows_affected' => $stmt->rowCount(),
        ]);

    } catch (Throwable $e) {
        error_log('do_sign save error: ' . $e->getMessage());
        dod_sign_json([
            'success' => false,
            'error'   => 'Save failed: ' . $e->getMessage(),
        ], 500);
    }
}
?>
<!-- ══════════════════════════════════════════════════════════
     Proof of Delivery — Digital Signature Modal
     File : modules/do_dispatch/do_sign.php
     Usage: included at the bottom of do_dispatch.php
     ══════════════════════════════════════════════════════════ -->

<!-- ── Modal Overlay ──────────────────────────────────────────── -->
<div id="sign-modal-overlay" class="erp-search" style="display:none !important;">

    <div id="sign-modal" class="erp-search-dialog sm" role="dialog" aria-modal="true" aria-labelledby="sign-modal-title">

        <!-- Save confirmation (Yes/No) — shown after validation, before geolocation/save -->
        <div id="sign-confirm-overlay" class="erp-search-confirm" style="display:none;">
            <div class="erp-search-confirm-box">
                <div class="erp-search-head">
                    <h4 class="erp-search-title"><i class="fa fa-exclamation-triangle"></i>Confirm signature save</h4>
                </div>
                <div class="erp-search-content">
                    <p class="erp-search-text">Please confirm before saving this Proof of Delivery.</p>
                    <div class="erp-search-panel">
                        <div class="erp-search-panel-label">Customer</div>
                        <div id="sign-confirm-customer" class="erp-search-panel-value"></div>
                        <div class="erp-search-subtitle">DO <span id="sign-confirm-doc"></span></div>
                    </div>
                    <p class="erp-search-text">Save signature, receiver name and receiver number for this customer?</p>
                </div>
                <div class="erp-search-foot">
                    <button id="sign-confirm-no" type="button" class="erp-search-btn">No</button>
                    <button id="sign-confirm-yes" type="button" class="erp-search-btn primary">Yes, Save</button>
                </div>
            </div>
        </div>

        <div class="erp-search-head">
            <h4 class="erp-search-title" id="sign-modal-title"><i class="fa fa-pencil-square-o"></i>Proof of Delivery</h4>
            <button id="sign-modal-close" type="button" class="erp-search-close" title="Close" aria-label="Close"><i class="fa fa-times"></i></button>
        </div>

        <div class="erp-search-content">

            <p id="sign-modal-subtitle" class="erp-search-text">
                Please provide a digital signature to confirm receipt of shipment
                <strong id="sign-doc-label"></strong>.
            </p>

            <div>
                <label class="erp-search-label">Digital Signature <span class="req">*</span></label>
                <div class="dod-sign-pad">
                    <canvas id="sign-canvas" style="touch-action:none;"></canvas>
                    <div id="sign-canvas-placeholder" class="dod-sign-pad-hint">
                        <i class="fa fa-pencil"></i>
                        <span>Sign here using touch or mouse</span>
                    </div>
                </div>
                <p id="sign-canvas-error" class="erp-search-error" style="display:none;">
                    Please draw your signature before saving.
                </p>
            </div>

            <div class="erp-search-grid">
                <div>
                    <label class="erp-search-label" for="sign-receiver-name">Receiver Name <span class="req">*</span></label>
                    <input id="sign-receiver-name" type="text" class="erp-search-field" placeholder="Enter full name" autocomplete="off">
                    <p id="sign-name-error" class="erp-search-error" style="display:none;">
                        Receiver name is required.
                    </p>
                </div>
                <div>
                    <label class="erp-search-label" for="sign-receiver-contact">Receiver Number <span class="req">*</span></label>
                    <input id="sign-receiver-contact" type="text" class="erp-search-field" placeholder="Phone or contact number" autocomplete="off">
                    <p id="sign-contact-error" class="erp-search-error" style="display:none;">
                        Receiver number is required.
                    </p>
                </div>
            </div>

            <div>
                <label class="erp-search-label">Date &amp; Time</label>
                <div class="erp-search-panel dod-sign-time">
                    <i class="fa fa-clock-o"></i>
                    <span id="sign-datetime"></span>
                </div>
            </div>

            <div class="erp-search-note">
                <i class="fa fa-shield"></i>
                <span>By signing, you confirm that the goods were received in good condition and the transaction is legally binding.</span>
            </div>

            <div id="sign-save-err" class="erp-search-msg error" style="display:none;"></div>

        </div>

        <div class="erp-search-foot">
            <button id="sign-clear-btn" type="button" class="erp-search-btn erp-search-foot-start">
                <i class="fa fa-trash-o"></i> Clear Signature
            </button>
            <button id="sign-cancel-btn" type="button" class="erp-search-btn">Cancel</button>
            <button id="sign-save-btn" type="button" class="erp-search-btn primary">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M5 13l4 4L19 7"/>
                </svg>
                Save Signature
            </button>
        </div>

    </div><!-- /.sign-modal -->
</div><!-- /.sign-modal-overlay -->

<script>
(function () {
    /* ── State ──────────────────────────────────────────────── */
    var overlay    = document.getElementById('sign-modal-overlay');
    var canvas     = document.getElementById('sign-canvas');
    var ctx        = canvas ? canvas.getContext('2d') : null;
    var placeholder = document.getElementById('sign-canvas-placeholder');
    var docLabel   = document.getElementById('sign-doc-label');
    var dtEl       = document.getElementById('sign-datetime');
    var nameInput    = document.getElementById('sign-receiver-name');
    var contactInput = document.getElementById('sign-receiver-contact');
    var nameErr    = document.getElementById('sign-name-error');
    var contactErr = document.getElementById('sign-contact-error');
    var canvasErr  = document.getElementById('sign-canvas-error');

    var isDrawing  = false;
    var isEmpty    = true;
    var strokeInk  = false; /* true only after an actual drawn stroke/dot */
    var currentDoc = '';
    var currentCustomer = '';
    var confirmOverlay = document.getElementById('sign-confirm-overlay');
    var confirmCustomerEl = document.getElementById('sign-confirm-customer');
    var confirmDocEl = document.getElementById('sign-confirm-doc');
    var confirmYesBtn = document.getElementById('sign-confirm-yes');
    var confirmNoBtn = document.getElementById('sign-confirm-no');

    function hideConfirm() {
        if (confirmOverlay) confirmOverlay.style.display = 'none';
    }

    function showConfirm() {
        if (confirmCustomerEl) {
            confirmCustomerEl.textContent = currentCustomer || '—';
        }
        if (confirmDocEl) {
            confirmDocEl.textContent = currentDoc || '—';
        }
        if (confirmOverlay) confirmOverlay.style.display = 'flex';
    }

    function getCustomerNameForDoc(docNo) {
        var row = document.querySelector('tr[data-doc="' + docNo + '"]');
        if (!row) return '';
        var el = row.querySelector('.dod-customer-name');
        return el ? (el.textContent || '').trim() : '';
    }

    function resetSaveButton() {
        var saveBtn = document.getElementById('sign-save-btn');
        var signErr = document.getElementById('sign-save-err');
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Save Signature';
        }
        if (signErr) {
            signErr.style.display = 'none';
            signErr.textContent = '';
        }
    }

    /* ── Open / Close ───────────────────────────────────────── */
    window.openSignModal = function (docNo) {
        currentDoc = docNo;
        currentCustomer = getCustomerNameForDoc(docNo);
        docLabel.textContent = '#' + docNo;
        nameInput.value = '';
        if (contactInput) contactInput.value = '';
        hideErrors();
        hideConfirm();
        resetSaveButton();
        clearCanvas();
        updateDateTime();
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    function closeModal() {
        hideConfirm();
        resetSaveButton();
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    document.getElementById('sign-modal-close').addEventListener('click', closeModal);
    document.getElementById('sign-cancel-btn').addEventListener('click', closeModal);

    /* Close on overlay click (outside card) */
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    /* ── Date / Time ────────────────────────────────────────── */
    function updateDateTime() {
        var now = new Date();
        var opts = { month: 'short', day: 'numeric', year: 'numeric',
                     hour: '2-digit', minute: '2-digit', hour12: true };
        dtEl.textContent = now.toLocaleString('en-US', opts);
    }

    /* ── Canvas setup ───────────────────────────────────────── */
    function resizeCanvas() {
        if (!canvas) return;
        var rect = canvas.getBoundingClientRect();
        canvas.width  = rect.width  * window.devicePixelRatio;
        canvas.height = rect.height * window.devicePixelRatio;
        ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
        ctx.strokeStyle = '#1d4ed8';
        ctx.lineWidth   = 2.2;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
    }

    function clearCanvas() {
        if (!ctx) return;
        resizeCanvas();
        isEmpty   = true;
        strokeInk = false;
        placeholder.style.display = '';
    }

    function getPos(e) {
        var rect = canvas.getBoundingClientRect();
        var src  = e.touches ? e.touches[0] : e;
        return {
            x: src.clientX - rect.left,
            y: src.clientY - rect.top
        };
    }

    function markHasInk() {
        isEmpty   = false;
        strokeInk = true;
        placeholder.style.display = 'none';
        canvasErr.style.display   = 'none';
    }

    /* True when canvas pixels contain drawn ink (not just a click with no stroke). */
    function canvasHasInk() {
        if (!ctx || !canvas) return false;
        try {
            var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
            var ink = 0;
            for (var i = 0; i < data.length; i += 4) {
                var a = data[i + 3];
                if (a < 20) continue;
                var r = data[i], g = data[i + 1], b = data[i + 2];
                if (r < 245 || g < 245 || b < 245) {
                    ink++;
                    if (ink >= 8) return true;
                }
            }
        } catch (e) {
            return strokeInk;
        }
        return false;
    }

    /* Mouse events — click alone must NOT count as a signature */
    canvas.addEventListener('mousedown', function (e) {
        isDrawing = true;
        ctx.beginPath();
        var p = getPos(e);
        ctx.moveTo(p.x, p.y);
    });
    canvas.addEventListener('mousemove', function (e) {
        if (!isDrawing) return;
        var p = getPos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        markHasInk();
    });
    canvas.addEventListener('mouseup',   function () { isDrawing = false; ctx.beginPath(); });
    canvas.addEventListener('mouseleave', function () { isDrawing = false; ctx.beginPath(); });

    /* Touch events */
    canvas.addEventListener('touchstart', function (e) {
        e.preventDefault();
        isDrawing = true;
        ctx.beginPath();
        var p = getPos(e);
        ctx.moveTo(p.x, p.y);
    }, { passive: false });
    canvas.addEventListener('touchmove', function (e) {
        e.preventDefault();
        if (!isDrawing) return;
        var p = getPos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        markHasInk();
    }, { passive: false });
    canvas.addEventListener('touchend', function () { isDrawing = false; ctx.beginPath(); });

    /* ── Clear button ───────────────────────────────────────── */
    document.getElementById('sign-clear-btn').addEventListener('click', clearCanvas);

    /* ── Validation helpers ─────────────────────────────────── */
    function hideErrors() {
        canvasErr.style.display = 'none';
        nameErr.style.display   = 'none';
        nameInput.classList.remove('border-red-400', 'ring-red-200');
        if (contactErr) contactErr.style.display = 'none';
        if (contactInput) contactInput.classList.remove('border-red-400', 'ring-red-200');
        var locErr = document.getElementById('sign-location-error');
        if (locErr) locErr.style.display = 'none';
    }

    function disableSignButtonForDoc(docNo) {
        var row = document.querySelector('tr[data-doc="' + docNo + '"]');
        if (!row) return;
        var signBtn = row.querySelector('.dod-action-btn--sign');
        if (!signBtn || signBtn.tagName !== 'BUTTON') return;
        var disabled = document.createElement('span');
        disabled.className = 'dod-action-btn dod-action-btn--sign inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-300 bg-gray-50 border border-gray-100 cursor-not-allowed opacity-60';
        disabled.title = 'Signature already captured';
        disabled.innerHTML = '<i class="fa fa-pencil-square-o text-sm"></i>';
        signBtn.parentNode.replaceChild(disabled, signBtn);
    }

    function enableDispatchButtonForDoc(docNo) {
        var row = document.querySelector('tr[data-doc="' + docNo + '"]');
        if (!row) return;
        var dispatchEl = row.querySelector('.dod-action-btn--dispatch');
        if (!dispatchEl || dispatchEl.tagName === 'BUTTON') return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dod-action-btn dod-action-btn--dispatch inline-flex items-center justify-center w-8 h-8 rounded-lg text-white bg-blue-600 hover:bg-blue-700 shadow-sm hover:shadow transition-all active:scale-95';
        btn.title = 'Dispatch';
        btn.setAttribute('data-doc', docNo);
        btn.innerHTML = '<i class="fa fa-paper-plane text-sm"></i>';
        dispatchEl.parentNode.replaceChild(btn, dispatchEl);
    }

    function requestReceiverLocation() {
        return new Promise(function (resolve, reject) {
            if (!navigator.geolocation) {
                reject(new Error('Geolocation is not supported by this browser.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    resolve({
                        latitude  : pos.coords.latitude,
                        longitude : pos.coords.longitude,
                        accuracy  : pos.coords.accuracy
                    });
                },
                function (err) {
                    if (err && err.code === 1) {
                        reject(new Error('Location permission is required to save the receiver signature.'));
                    } else if (err && err.code === 2) {
                        reject(new Error('Unable to determine location. Please enable GPS and try again.'));
                    } else if (err && err.code === 3) {
                        reject(new Error('Location request timed out. Please try again.'));
                    } else {
                        reject(new Error('Location permission is required to save the receiver signature.'));
                    }
                },
                {
                    enableHighAccuracy : true,
                    timeout            : 10000,
                    maximumAge         : 0
                }
            );
        });
    }

    function postSignatureSave(payload) {
        var saveBtn = document.getElementById('sign-save-btn');
        var signErr = document.getElementById('sign-save-err');

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Saving…';
        if (signErr) signErr.style.display = 'none';

        fetch('do_sign.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body   : JSON.stringify(payload)
        })
        .then(function (r) {
            return r.text().then(function (text) {
                var data = null;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (e) {
                    throw new Error('Unexpected response (HTTP ' + r.status + '). Please refresh and try again.');
                }
                if (!data) {
                    throw new Error('Empty server response (HTTP ' + r.status + ').');
                }
                return { ok: r.ok, status: r.status, data: data };
            });
        })
        .then(function (res) {
            var d = res.data;
            if (d.success) {
                closeModal();
                showSignToast('Signature saved successfully.');
                disableSignButtonForDoc(currentDoc);

                var row = document.querySelector('tr[data-doc="' + currentDoc + '"]');
                if (row) {
                    var badge = row.querySelector('.dod-badge');
                    if (badge) {
                        badge.className = 'dod-badge dod-badge-signed inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 border border-green-200';
                        badge.innerHTML = '<span class="dod-badge-dot w-1.5 h-1.5 rounded-full bg-green-500"></span> Signed';
                    }
                    enableDispatchButtonForDoc(currentDoc);
                }
            } else {
                resetSaveButton();
                if (signErr) {
                    signErr.textContent   = d.error || 'Save failed. Please try again.';
                    signErr.style.display = 'block';
                }
            }
        })
        .catch(function (err) {
            resetSaveButton();
            if (signErr) {
                signErr.textContent   = err.message || 'Request failed. Please try again.';
                signErr.style.display = 'block';
            }
        });
    }

    /* Existing save flow (geolocation → POST). Called only after Yes confirmation. */
    function proceedSaveSignature() {
        var signErr = document.getElementById('sign-save-err');
        var saveBtn = document.getElementById('sign-save-btn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Getting location…';

        requestReceiverLocation()
        .then(function (location) {
            var signatureData = canvas.toDataURL('image/png');
            var receiverName     = nameInput.value.trim();
            var receiverContact  = contactInput ? contactInput.value.trim() : '';
            var timestamp        = dtEl.textContent;
            var payload = {
                doc_no           : currentDoc,
                signature        : signatureData,
                receiver_name    : receiverName,
                receiver_contact : receiverContact,
                timestamp        : timestamp,
                receiver_latitude          : location.latitude,
                receiver_longitude         : location.longitude,
                receiver_location_accuracy : location.accuracy
            };
            postSignatureSave(payload);
        })
        .catch(function (err) {
            resetSaveButton();
            if (signErr) {
                signErr.textContent   = err.message || 'Location permission is required to save the receiver signature.';
                signErr.style.display = 'block';
            }
        });
    }

    /* ── Save button → validate, then confirm Yes/No ─────────── */
    document.getElementById('sign-save-btn').addEventListener('click', function () {
        hideErrors();
        var valid = true;

        if (isEmpty || !strokeInk || !canvasHasInk()) {
            canvasErr.style.display = 'block';
            valid = false;
        }
        if (!nameInput.value.trim()) {
            nameErr.style.display = 'block';
            nameInput.classList.add('border-red-400', 'ring-2', 'ring-red-200');
            valid = false;
        }
        if (!contactInput || !contactInput.value.trim()) {
            if (contactErr) contactErr.style.display = 'block';
            if (contactInput) contactInput.classList.add('border-red-400', 'ring-2', 'ring-red-200');
            valid = false;
        }
        if (!valid) return;

        // Confirmation before geolocation / save
        if (!currentCustomer) {
            currentCustomer = getCustomerNameForDoc(currentDoc);
        }
        showConfirm();
    });

    if (confirmNoBtn) {
        confirmNoBtn.addEventListener('click', function () {
            hideConfirm();
            // Stay on Proof of Delivery — no save
        });
    }

    if (confirmYesBtn) {
        confirmYesBtn.addEventListener('click', function () {
            hideConfirm();
            proceedSaveSignature();
        });
    }

    /* ── Resize canvas when window resizes ──────────────────── */
    window.addEventListener('resize', function () {
        if (overlay.style.display !== 'none') resizeCanvas();
    });

    /* Initial resize when modal first opens */
    var origOpen = window.openSignModal;
    window.openSignModal = function (docNo) {
        origOpen(docNo);
        requestAnimationFrame(resizeCanvas);
    };

    /* ── Toast helper ───────────────────────────────────────────── */
    function showSignToast(msg) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = [
            'position:fixed', 'bottom:24px', 'left:50%', 'transform:translateX(-50%)',
            'background:#16a34a', 'color:#fff', 'font-size:13px', 'font-weight:600',
            'padding:10px 22px', 'border-radius:999px',
            'box-shadow:0 4px 16px rgba(0,0,0,.18)',
            'z-index:99999', 'opacity:0',
            'transition:opacity .3s'
        ].join(';');
        document.body.appendChild(t);
        requestAnimationFrame(function () {
            t.style.opacity = '1';
            setTimeout(function () {
                t.style.opacity = '0';
                setTimeout(function () { t.remove(); }, 350);
            }, 3000);
        });
    }

}());
</script>
