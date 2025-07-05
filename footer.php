<?php
// File: includes/footer.php

// Close the main-content and content-wrapper divs opened in header.php
?>
        </div> </div> <div id="loading-overlay">
        <div class="spinner-border" role="status"></div>
        <div class="loading-text">Loading...</div>
    </div>

    <script src="../assets/js/utils.js"></script>
    <script src="../assets/js/script.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // This global listener can catch alert-close buttons from initial PHP-rendered alerts
            // that are not yet replaced by showToast.
            const alertCloseButtons = document.querySelectorAll('.alert-close');
            alertCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.style.display = 'none';
                });
            });

            // Any other global DOMContentLoaded initialization not handled by utils.js
        });
    </script>
</body>
</html>