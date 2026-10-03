<?php

/**
 * Folder Watch settings (Bootstrap 5, new-UI): how often and how hard folders
 * are scanned. TMDb matching and the genre → category/bouquet mapping are core
 * settings (Settings → VOD Import). Body-only view: the controller renders the unified admin shell around it and
 * includes settings_watch_scripts.php afterwards. Posts to post.php?action=settings_watch.
 */

?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0">Folder Watch Settings</h4>
</div>

<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Watch settings successfully updated!
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form id="watch-settings-form" method="POST" action="post.php?action=settings_watch" autocomplete="off">
            <div class="row mb-6">
                <div class="col-md-6">
                    <label class="form-label" for="scan_seconds">Scan Frequency <i title="Scan a folder every X seconds." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                    <input type="text" class="form-control text-center" id="scan_seconds" name="scan_seconds" value="<?php echo htmlspecialchars($rSettings['scan_seconds']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="max_items">Max Items <i title="Maximum number of items to add per folder per scan. Set this to 0 to scan everything." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                    <input type="text" class="form-control text-center" id="max_items" name="max_items" value="<?php echo htmlspecialchars($rSettings['max_items']); ?>">
                </div>
            </div>
            <p class="text-body-secondary mb-0">Parallel imports, TMDb match percentage, parsers and the genre &rarr; category / bouquet mapping are in <a href="settings">Settings &rarr; VOD Import</a>; they apply to every import, including this module's.</p>

            <div class="text-end mt-4">
                <button type="submit" name="submit_settings" id="save-settings" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
