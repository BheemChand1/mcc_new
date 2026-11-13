<?php if (basename($_SERVER['PHP_SELF']) !== 'index.php'): ?>
<footer class="app-footer text-center py-4 mt-auto" style="background: linear-gradient(180deg, #07182c 0%, #03101e 100%); border-top: 1px solid #0b476a; color: #8aa3b9;">
  <div class="container-fluid">
    <div class="row align-items-center">
      <div class="col-md-6 text-md-start">
        <p class="mb-0 small" style="color: #8aa3b9;">
          &copy; <?php echo date('Y'); ?> <strong style="color: #f4f8fc;">MCC Auditor Portal</strong>. All rights reserved.
        </p>
      </div>
      <div class="col-md-6 text-md-end">
        <span class="badge bg-secondary bg-opacity-25 text-info px-2 py-1 small">Station: <?= htmlspecialchars($stationName ?? 'Station') ?></span>
      </div>
    </div>
  </div>
</footer>
<?php endif; ?>

    </div> <!-- Closing app-wrapper -->

    <script
      src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js"
      integrity="sha256-dghWARbRe2eLlIJ56wNB+b760ywulqK3DzZYEpsg2fQ="
      crossorigin="anonymous"></script>
    <script
      src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
      integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r"
      crossorigin="anonymous"></script>
    <script
      src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js"
      integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy"
      crossorigin="anonymous"></script>
    <script
      src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-beta3/dist/js/adminlte.js"></script>
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof flatpickr !== 'undefined') {
            flatpickr('input[type="date"]', {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "d-m-Y",
                allowInput: true
            });
        }
    });

    const SELECTOR_SIDEBAR_WRAPPER = '.sidebar-wrapper';
    document.addEventListener('DOMContentLoaded', function () {
        const sidebarWrapper = document.querySelector(SELECTOR_SIDEBAR_WRAPPER);
        if (sidebarWrapper && typeof OverlayScrollbarsGlobal?.OverlayScrollbars !== 'undefined') {
            OverlayScrollbarsGlobal.OverlayScrollbars(sidebarWrapper, {
                scrollbars: {
                    theme: 'os-theme-light',
                    autoHide: 'leave',
                    clickScroll: true,
                },
            });
        }
    });

    // Auto-active Sidebar Links
    document.addEventListener("DOMContentLoaded", function() {
        const currentPath = window.location.pathname.split("/").pop();
        const navLinks = document.querySelectorAll(".nav-sidebar .nav-link");
        
        navLinks.forEach(link => {
            const href = link.getAttribute("href");
            if (href === currentPath || (currentPath === "" && href === "./index.php")) {
                link.classList.add("active");
                // Open parent treeview if any
                const parentTree = link.closest(".nav-treeview");
                if (parentTree) {
                    parentTree.style.display = "block";
                    parentTree.closest(".nav-item").classList.add("menu-open");
                    parentTree.closest(".nav-item").querySelector(".nav-link").classList.add("active");
                }
            } else {
                link.classList.remove("active");
            }
        });
    });

    // Add token-based sheet deletion beside every editor "Edit Sheet" action.
    // The endpoint performs the final report-family and station validation.
    document.addEventListener('DOMContentLoaded', function () {
        const currentReport = window.location.pathname.split('/').pop();
        document.querySelectorAll('a[title="Edit Sheet"]').forEach(function (editLink) {
            const editUrl = new URL(editLink.href, window.location.href);
            const tokenId = editUrl.searchParams.get('edit');
            if (!tokenId || editUrl.searchParams.has('edit_date')) return;

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'btn btn-sm btn-danger fw-bold text-white no-print shadow-sm px-3 ms-1';
            deleteButton.title = 'Delete Sheet';
            deleteButton.innerHTML = '<i class="bi bi-trash me-1"></i> Delete Sheet';
            deleteButton.addEventListener('click', async function () {
                if (!window.confirm('Delete this complete sheet and all its report data? This cannot be undone.')) return;
                deleteButton.disabled = true;
                try {
                    const body = new URLSearchParams({ token_id: tokenId, report_key: currentReport });
                    const response = await fetch('delete-sheet.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body.toString()
                    });
                    const result = await response.json();
                    if (!result.success) throw new Error(result.message || 'Delete failed.');
                    window.location.reload();
                } catch (error) {
                    window.alert(error.message || 'Unable to delete the sheet.');
                    deleteButton.disabled = false;
                }
            });
            editLink.insertAdjacentElement('afterend', deleteButton);
        });
    });
    </script>
</body>
</html>
