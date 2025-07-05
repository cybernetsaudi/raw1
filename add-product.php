<?php
// File: add-product.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class
include_once '../includes/header.php'; // Include header before any HTML output

// Check user authentication and role (e.g., 'owner' or 'incharge' can add/edit products).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to manage products.";
    header('Location: products.php'); // Redirect to products list
    exit;
}

$page_title = "Add New Product";
$product_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$product = null; // Will hold product data if editing

// Initialize database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

try {
    if ($product_id) {
        // Fetch existing product data for editing.
        $product_query = "SELECT * FROM products WHERE id = ?";
        $product_stmt = $db->prepare($product_query);
        $product_stmt->execute([$product_id]);
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("Product not found for editing.");
        }
        $page_title = "Edit Product: " . htmlspecialchars($product['name']);

        // Log viewing the edit page.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'products',
            'Accessed Edit Product page for ' . htmlspecialchars($product['name']) . ' (ID: ' . $product_id . ')',
            $product_id
        );

    } else {
        // Log viewing the add page.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'products',
            'Accessed Add New Product page',
            null
        );
    }

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'products',
        'Error loading product form: ' . $e->getMessage(),
        $product_id ?? null
    );
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: products.php'); // Redirect if product not found or invalid ID
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="products.php">Products</a> &gt;
    <span><?php echo htmlspecialchars($page_title); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2><?php echo htmlspecialchars($page_title); ?></h2>
    </div>
    <div class="page-actions">
        <a href="products.php" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Products
        </a>
    </div>
</div>

<?php
// Display success or error messages from session
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i><span>' . htmlspecialchars($_SESSION['success_message']) . '</span><span class="alert-close">&times;</span></div>';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span>' . htmlspecialchars($_SESSION['error_message']) . '</span><span class="alert-close">&times;</span></div>';
    unset($_SESSION['error_message']);
}
?>

<div class="container-fluid">
    <div class="card add-product-card">
        <div class="card-header">
            <h3>Product Information</h3>
        </div>
        <div class="card-content">
            <form id="productForm" method="post" action="../api/save-product.php" enctype="multipart/form-data">
                <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id'] ?? ''); ?>">
                <input type="hidden" name="current_image_path" value="<?php echo htmlspecialchars($product['image_path'] ?? ''); ?>">

                <div class="form-group">
                    <label for="name">Product Name:</label>
                    <input type="text" id="name" name="name" required placeholder="e.g., Men's Polo Shirt" value="<?php echo htmlspecialchars($product['name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="sku">SKU (Stock Keeping Unit):</label>
                    <input type="text" id="sku" name="sku" required placeholder="e.g., M-POLO-BLUE-L" value="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>">
                    <small class="form-text text-muted">Must be unique.</small>
                </div>

                <div class="form-group">
                    <label for="category">Category:</label>
                    <input type="text" id="category" name="category" required placeholder="e.g., Apparel, Accessories" value="<?php echo htmlspecialchars($product['category'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional):</label>
                    <textarea id="description" name="description" rows="4" placeholder="Detailed product description..."><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="product_image">Product Image (Optional):</label>
                    <input type="file" id="product_image" name="product_image" accept="image/jpeg, image/png, image/gif">
                    <small class="form-text text-muted">Max 5MB. Allowed formats: JPG, PNG, GIF. <?php echo $product_id ? 'Upload new image to replace existing one.' : ''; ?></small>
                    <div id="image-preview" style="margin-top: 10px; max-width: 200px; <?php echo empty($product['image_path']) ? 'display: none;' : ''; ?>">
                        <img src="../<?php echo htmlspecialchars($product['image_path'] ?? '#'); ?>" alt="Image Preview" style="width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>

                <div class="form-actions">
                    <a href="products.php" class="button secondary">Cancel</a>
                    <button type="submit" class="button primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons (existing logic)
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    // Image preview logic
    const productImageInput = document.getElementById('product_image');
    const imagePreviewContainer = document.getElementById('image-preview');
    const imagePreview = imagePreviewContainer.querySelector('img');

    if (productImageInput) {
        productImageInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    imagePreviewContainer.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                // If no file selected, and there was an original image, show it.
                // Otherwise, hide preview.
                if (document.getElementById('current_image_path').value) {
                    imagePreview.src = '../' + document.getElementById('current_image_path').value;
                    imagePreviewContainer.style.display = 'block';
                } else {
                    imagePreview.src = '#';
                    imagePreviewContainer.style.display = 'none';
                }
            }
        });
    }

    // Client-side form validation and AJAX submission
    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Validate required fields
            const nameInput = document.getElementById('name');
            const skuInput = document.getElementById('sku');
            const categoryInput = document.getElementById('category');

            if (!nameInput.value.trim()) {
                showValidationError(nameInput, 'Product name is required.');
                isValid = false;
            }
            if (!skuInput.value.trim()) {
                showValidationError(skuInput, 'SKU is required.');
                isValid = false;
            }
            if (!categoryInput.value.trim()) {
                showValidationError(categoryInput, 'Category is required.');
                isValid = false;
            }

            // Validate image file type and size (client-side)
            const imageFile = productImageInput.files[0];
            if (imageFile) {
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                const maxFileSize = 5 * 1024 * 1024; // 5 MB

                if (!allowedTypes.includes(imageFile.type)) {
                    showValidationError(productImageInput, 'Invalid image file type. Only JPG, PNG, GIF are allowed.');
                    isValid = false;
                }
                if (imageFile.size > maxFileSize) {
                    showValidationError(productImageInput, 'Image file size exceeds 5MB limit.');
                    isValid = false;
                }
            }


            if (!isValid) {
                return; // Stop if validation fails
            }

            // Show loading state
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Saving...';

            const formData = new FormData(this); // Get form data, important for file uploads
            const actionUrl = this.action; // Get form action URL

            fetch(actionUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Product'; // Reset button text

                if (typeof showToast === 'function') { // Use showToast for notifications
                    showToast(data.success ? 'success' : 'error', data.message);
                } else {
                    alert(data.message); // Fallback
                }
                if (data.success) {
                    // Redirect to product list after successful save
                    window.location.href = 'products.php';
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Product';
                console.error('Error saving product:', error);
                if (typeof showToast === 'function') {
                    showToast('error', 'An unexpected error occurred while saving the product.');
                } else {
                    alert('An unexpected error occurred.');
                }
            });
        });
    }

    // Helper functions for validation (can be moved to utils.js later for global use)
    function showValidationError(element, message) {
        const existingError = element.parentElement.querySelector('.validation-error');
        if (existingError) { existingError.remove(); }
        element.classList.add('invalid-input');
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;
        // For file input, append error next to the input itself, not its parent
        if (element.type === 'file') {
            element.parentNode.insertBefore(errorElement, element.nextSibling);
        } else {
            element.parentElement.appendChild(errorElement);
        }
        element.focus();
    }

    // Remove validation error when input changes
    const formInputs = document.querySelectorAll('#productForm input, #productForm select, #productForm textarea');
    formInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            let errorElement;
            if (this.type === 'file') {
                errorElement = this.parentNode.querySelector('.validation-error');
            } else {
                errorElement = this.parentElement.querySelector('.validation-error');
            }
            if (errorElement) { errorElement.remove(); }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>