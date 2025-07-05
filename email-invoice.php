<?php
// File: api/email-invoice.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include PHPMailer Autoloader (adjust path if Composer's autoloader is in a different location)
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include Dompdf Autoloader (needed to generate PDF content for attachment)
use Dompdf\Dompdf;
use Dompdf\Options;

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process POST request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check user authentication and role BEFORE processing request.
        // Owners and Shopkeepers can send invoices.
        if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'shopkeeper')) {
            throw new Exception("Unauthorized access. You do not have permission to send emails.");
        }

        // Get and sanitize input data.
        $sale_id = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
        $recipient_email = trim($_POST['recipient_email'] ?? ''); // Optional: allow custom recipient

        if (!$sale_id) {
            throw new Exception("Missing or invalid Sale ID.");
        }

        // Fetch sale details (similar to generate-invoice-pdf.php and view-sale.php)
        $sale_query = "SELECT s.*, c.name as customer_name, c.email as customer_email,
                              u.full_name as created_by_name
                       FROM sales s
                       JOIN customers c ON s.customer_id = c.id
                       LEFT JOIN users u ON s.created_by = u.id
                       WHERE s.id = ?";
        // If shopkeeper, ensure they can only email invoices for sales they created
        if ($_SESSION['role'] === 'shopkeeper') {
            $sale_query .= " AND s.created_by = " . $_SESSION['user_id'];
        }
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->execute([$sale_id]);
        $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception("Sale record not found or you do not have permission to email invoice for it.");
        }

        $customer_email = $sale['customer_email'];
        if (empty($customer_email) && empty($recipient_email)) {
            throw new Exception("Customer email is missing and no recipient email was provided.");
        }
        $send_to_email = !empty($recipient_email) ? $recipient_email : $customer_email;

        if (!filter_var($send_to_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid recipient email address: " . htmlspecialchars($send_to_email));
        }

        // --- Generate PDF Content for Attachment (reusing Dompdf logic) ---
        // This part duplicates some logic from generate-invoice-pdf.php to get PDF content.
        // In a more advanced architecture, generate-invoice-pdf could return content directly.
        $sale_items_query = "SELECT si.*, p.name as product_name, p.sku as product_sku
                             FROM sale_items si
                             JOIN products p ON si.product_id = p.id
                             WHERE si.sale_id = ?";
        $sale_items_stmt = $db->prepare($sale_items_query);
        $sale_items_stmt->execute([$sale_id]);
        $sale_items = $sale_items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $payments_query = "SELECT SUM(amount) FROM payments WHERE sale_id = ?";
        $payments_stmt = $db->prepare($payments_query);
        $payments_stmt->execute([$sale_id]);
        $total_paid_amount = $payments_stmt->fetchColumn() ?: 0;
        $amount_due = $sale['net_amount'] - $total_paid_amount;

        // HTML for PDF (same as in generate-invoice-pdf.php)
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <title>Invoice #' . htmlspecialchars($sale['invoice_number']) . '</title>
            <style>
                body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.5; color: #333; }
                .container { width: 100%; margin: 0 auto; padding: 20px; }
                .header, .footer { text-align: center; margin-bottom: 20px; }
                .header h1 { margin: 0; padding: 0; font-size: 20pt; color: #1a73e8; }
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

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf_content = $dompdf->output(); // Get PDF content as string

        // --- PHPMailer Configuration and Sending ---
        $mail = new PHPMailer(true); // Passing `true` enables exceptions

        // SMTP configuration (replace with your actual SMTP details)
        $mail->isSMTP();
        $mail->Host = 'smtp.yourdomain.com'; // Your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'your_email@yourdomain.com'; // SMTP username
        $mail->Password = 'your_email_password';     // SMTP password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Use TLS encryption
        $mail->Port = 587; // TLS port, often 587 (or 465 for SSL)

        // Sender and Recipient
        $mail->setFrom('no-reply@yourdomain.com', 'Garment Manufacturing System'); // Your sender email and name
        $mail->addAddress($send_to_email, htmlspecialchars($sale['customer_name'])); // Recipient's email and name (optional)
        $mail->addReplyTo('support@yourdomain.com', 'Support Team'); // Optional: Reply-to address

        // Content
        $mail->isHTML(true); // Set email format to HTML
        $mail->Subject = 'Your Invoice from Garment Manufacturing System - #' . $sale['invoice_number'];
        $mail->Body    = 'Dear ' . htmlspecialchars($sale['customer_name']) . ',<br><br>'
                       . 'Please find attached your invoice for Sale #' . htmlspecialchars($sale['invoice_number']) . ' from Garment Manufacturing System.<br><br>'
                       . '<strong>Total Amount Due: Rs. ' . number_format($amount_due, 2) . '</strong><br><br>'
                       . 'Thank you for your business!<br>'
                       . 'Best regards,<br>'
                       . 'The Garment Manufacturing Team';
        $mail->AltBody = 'Dear ' . htmlspecialchars($sale['customer_name']) . ',\n\n'
                       . 'Please find attached your invoice for Sale #' . htmlspecialchars($sale['invoice_number']) . ' from Garment Manufacturing System.\n\n'
                       . 'Total Amount Due: Rs. ' . number_format($amount_due, 2) . '\n\n'
                       . 'Thank you for your business!\n'
                       . 'Best regards,\n'
                       . 'The Garment Manufacturing Team';

        // Attachment (the generated PDF)
        $mail->addStringAttachment($pdf_content, 'invoice_' . $sale['invoice_number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');

        $mail->send();

        // Log the email sending activity.
        $auth->logActivity(
            $_SESSION['user_id'],
            'email', // Custom action type for email sending
            'sales',
            'Emailed invoice for Sale #' . htmlspecialchars($sale['invoice_number']) . ' to ' . htmlspecialchars($send_to_email),
            $sale_id
        );

        echo json_encode(['success' => true, 'message' => 'Invoice emailed successfully to ' . htmlspecialchars($send_to_email) . '.']);
        exit;

    } catch (Exception $e) {
        // Log the error.
        $error_message = 'Failed to email invoice for Sale ' . ($sale_id ?? 'N/A') . ': ' . $mail->ErrorInfo . ' | Exception: ' . $e->getMessage();
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'sales',
            $error_message,
            $sale_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'Failed to send email. Mailer Error: ' . $mail->ErrorInfo . ' (Details: ' . $e->getMessage() . ')']);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}