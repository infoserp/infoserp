<?php
// Attachments card for the document side panel (.doc-page-aside).
// Opens the same action as the toolbar ATTACH button.
?>
<section class="doc-panel doc-attach-panel">
    <h3 class="doc-section-title">Attachments</h3>
    <div class="doc-attach-empty"><i class="fa fa-paperclip"></i> No files attached</div>
    <button type="button" class="doc-drop" onclick="erpToolbarAction('attach')">
        <i class="fa fa-cloud-upload"></i> Click to attach files
    </button>
</section>
