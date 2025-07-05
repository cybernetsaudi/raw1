<?php
// File: api/generate-invoice-pdf.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include Dompdf Autoloader.
// IMPORTANT: Adjust this path if your Composer's vendor directory is not directly
// one level up from your 'api' folder (e.g., if 'api' is inside 'public_html').
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set headers to ensure the browser prompts for PDF download.
// These headers MUST be sent before any other output.
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="invoice_' . ($_GET['sale_id'] ?? 'unknown') . '.pdf"');

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$sale_id = filter_var($_GET['sale_id'] ?? null, FILTER_VALIDATE_INT);

try {
    // Check user authentication and role BEFORE fetching sensitive data.
    // Owners and Shopkeepers can generate invoices.
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'shopkeeper')) {
        throw new Exception("Unauthorized access. You do not have permission to generate this invoice.");
    }

    if (!$sale_id) {
        throw new Exception("Missing or invalid Sale ID.");
    }

    // Fetch sale details (similar to view-sale.php logic)
    $sale_query = "SELECT s.*, c.name as customer_name, c.phone as customer_phone, c.email as customer_email, c.address as customer_address,
                          u.full_name as created_by_name
                   FROM sales s
                   JOIN customers c ON s.customer_id = c.id
                   LEFT JOIN users u ON s.created_by = u.id
                   WHERE s.id = ?";
    // If shopkeeper, ensure they can only generate PDFs for sales they created
    if ($_SESSION['role'] === 'shopkeeper') {
        $sale_query .= " AND s.created_by = " . $_SESSION['user_id'];
    }
    $sale_stmt = $db->prepare($sale_query);
    $sale_stmt->execute([$sale_id]);
    $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new Exception("Sale record not found or you do not have permission to generate invoice for it.");
    }

    // Fetch sale items
    $sale_items_query = "SELECT si.*, p.name as product_name, p.sku as product_sku
                         FROM sale_items si
                         JOIN products p ON si.product_id = p.id
                         WHERE si.sale_id = ?";
    $sale_items_stmt = $db->prepare($sale_items_query);
    $sale_items_stmt->execute([$sale_id]);
    $sale_items = $sale_items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch payments for total paid calculation
    $payments_query = "SELECT SUM(amount) FROM payments WHERE sale_id = ?";
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->execute([$sale_id]);
    $total_paid_amount = $payments_stmt->fetchColumn() ?: 0;
    $amount_due = $sale['net_amount'] - $total_paid_amount;

    // Log the PDF generation activity
    $auth->logActivity(
        $_SESSION['user_id'],
        'export', // Action type for exporting data
        'sales',
        'Generated PDF invoice for Sale #' . htmlspecialchars($sale['invoice_number']),
        $sale_id
    );

    // --- HTML Content for the PDF ---
    // This HTML forms the content of your invoice. You can customize its design extensively.
    // For complex designs, consider storing this HTML in a separate template file (e.g., templates/invoice_template.html)
    // and using `file_get_contents()` to load it, then replacing placeholders.
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <title>Invoice #' . htmlspecialchars($sale['invoice_number']) . '</title>
        <style>
            /* Basic PDF Styles - Customize this to match your brand! */
            body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.5; color: #333; }
            .container { width: 100%; margin: 0 auto; padding: 20px; }
            .header, .footer { text-align: center; margin-bottom: 20px; }
            .header h1 { margin: 0; padding: 0; font-size: 20pt; color: #1a73e8; } /* Primary blue color */
            .header p { margin: 2px 0; font-size: 9pt; }
            .invoice-details, .customer-details { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
            .invoice-details td, .customer-details td { padding: 8px; vertical-align: top; }
            .invoice-details .label, .customer-details .label { font-weight: bold; width: 30%; color: #555; }
            .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .items-table th, .items-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .items-table th { background-color: #f8f9fa; font-weight: bold; color: #333; }
            .summary-table { width: 40%; float: right; border-collapse: collapse; margin-top: 10px;}
            .summary-table td { padding: 5px 8px; border: 1px solid #ddd; }
            .summary-table .label { font-weight: bold; }
            .total-row { background-color: #e9ecef; font-weight: bold; }
            .text-right { text-align: right; }
            .notes { margin-top: 30px; font-size: 9pt; }
            .signature { margin-top: 50px; text-align: right; }
            .signature p { margin: 5px 0; }
            .footer { margin-top: 30px; border-top: 1px solid #eee; padding-top: 10px; font-size: 8pt; color: #666;}
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>INVOICE</h1>
                <p><strong>Garment Manufacturing System Co.</strong></p>
                <p>123 Fabric Lane, Textile City, TX 78901, Country</p>
                <p>Phone: +1 (555) 123-4567 | Email: contact@garmentsys.com | Web: www.garmentsys.com</p>
            </div>

            <table class="invoice-details">
                <tr>
                    <td class="label">Invoice Number:</td>
                    <td>#' . htmlspecialchars($sale['invoice_number']) . '</td>
                    <td class="label">Invoice Date:</td>
                    <td>' . htmlspecialchars($sale['sale_date']) . '</td>
                </tr>
                <tr>
                    <td class="label">Created By:</td>
                    <td>' . htmlspecialchars($sale['created_by_name']) . '</td>
                    <td class="label">Payment Status:</td>
                    <td>' . htmlspecialchars(ucfirst($sale['payment_status'])) . '</td>
                </tr>
            </table>

            <h2>Bill To:</h2>
            <table class="customer-details">
                <tr>
                    <td><strong>' . htmlspecialchars($sale['customer_name']) . '</strong></td>
                </tr>
                <tr>
                    <td>Phone: ' . htmlspecialchars($sale['customer_phone'] ?: 'N/A') . '</td>
                </tr>
                <tr>
                    <td>Email: ' . htmlspecialchars($sale['customer_email'] ?: 'N/A') . '</td>
                </tr>
                <tr>
                    <td>Address: ' . nl2br(htmlspecialchars($sale['customer_address'] ?: 'N/A')) . '</td>
                </tr>
            </table>

            <h2>Sale Items</h2>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Quantity</th>
                        <th>Unit Price (Rs.)</th>
                        <th>Total Price (Rs.)</th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($sale_items as $item) {
        $html .= '<tr>
                    <td>' . htmlspecialchars($item['product_name']) . '</td>
                    <td>' . htmlspecialchars($item['product_sku']) . '</td>
                    <td>' . number_format($item['quantity']) . '</td>
                    <td class="text-right">Rs. ' . number_format($item['unit_price'], 2) . '</td>
                    <td class="text-right">Rs. ' . number_format($item['total_price'], 2) . '</td>
                </tr>';
    }
    $html .= '
                </tbody>
            </table>

            <table class="summary-table">
                <tr>
                    <td class="label">Subtotal:</td>
                    <td class="text-right">Rs. ' . number_format($sale['total_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td class="label">Discount:</td>
                    <td class="text-right">Rs. ' . number_format($sale['discount_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td class="label">Tax:</td>
                    <td class="text-right">Rs. ' . number_format($sale['tax_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td class="label">Shipping Cost:</td>
                    <td class="text-right">Rs. ' . number_format($sale['shipping_cost'], 2) . '</td>
                </tr>
                <tr class="total-row">
                    <td class="label">Net Amount:</td>
                    <td class="text-right">Rs. ' . number_format($sale['net_amount'], 2) . '</td>
                </tr>
                <tr>
                    <td class="label">Amount Paid:</td>
                    <td class="text-right">Rs. ' . number_format($total_paid_amount, 2) . '</td>
                </tr>
                <tr class="total-row">
                    <td class="label">Amount Due:</td>
                    <td class="text-right">Rs. ' . number_format($amount_due, 2) . '</td>
                </tr>
            </table>

            <div style="clear: both;"></div>

            <div class="notes">
                <h3>Notes:</h3>
                <p>' . nl2br(htmlspecialchars($sale['notes'] ?: 'N/A')) . '</p>
            </div>

            <div class="signature">
                <p>_________________________</p>
                <p>Authorized Signature</p>
            </div>

            <div class="footer">
                <p>Thank you for your business!</p>
                <p>Generated on ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </div>
    </body>
    </html>';

    // --- Dompdf Configuration and Generation ---
    // Set options for Dompdf (e.g., enable HTML5 parser, remote assets if you use external CSS/images)
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans'); // Recommended for broader character support
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true); // Enable if your CSS or images are external URLs

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);

    // Set paper size and orientation (e.g., 'A4', 'portrait' or 'landscape')
    $dompdf->setPaper('A4', 'portrait');

    // Render the HTML as PDF
    $dompdf->render();

    // Output the generated PDF to Browser. Set attachment to true for download prompt.
    $dompdf->stream('invoice_' . $sale['invoice_number'] . '.pdf', ["Attachment" => true]);
    exit;

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'sales',
        'Failed to generate PDF invoice for Sale ' . ($sale_id ?? 'N/A') . ': ' . $e->getMessage(),
        $sale_id ?? null
    );

    // If headers haven't been sent, attempt to return a JSON error (best for API).
    // If headers were already sent (e.g., due to previous output or `header()` calls),
    // outputting JSON will fail. In that case, a plain text error is a fallback.
    if (!headers_sent()) {
        header('Content-Type: application/json'); // Change content type back to JSON for error
        echo json_encode(['success' => false, 'message' => 'Error generating PDF: ' . $e->getMessage()]);
    } else {
        // Fallback if headers already sent.
        echo 'Error generating PDF: ' . $e->getMessage();
    }
    exit;
}