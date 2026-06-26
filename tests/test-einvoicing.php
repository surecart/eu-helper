<?php
/**
 * Standalone logic tests for the E-Invoicing module's pure pieces.
 *
 * No WordPress/database required — stubs the few functions used, loads only the
 * DB-free classes (domain, state machine, idempotency, validator, the Storecove
 * payload mapper) and exercises them. Run:  php tests/test-einvoicing.php
 *
 * @package SureCartEuHelper
 */

define( 'ABSPATH', __DIR__ );

// Minimal stubs.
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) {
		return $s; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) {
		return number_format( (float) $n, (int) $d ); }
}

$dir = __DIR__ . '/../src/Modules/EInvoicing/';
require $dir . 'Domain/DocumentType.php';
require $dir . 'Domain/DocumentStatus.php';
require $dir . 'Domain/Environment.php';
require $dir . 'Domain/Money.php';
require $dir . 'Domain/Document.php';
require $dir . 'Workflow/IdempotencyKey.php';
require $dir . 'Workflow/DocumentValidator.php';
require $dir . 'Providers/Storecove/StorecoveDocumentMapper.php';

use SureCartEuHelper\Modules\EInvoicing\Domain\Document;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentType;
use SureCartEuHelper\Modules\EInvoicing\Domain\DocumentStatus;
use SureCartEuHelper\Modules\EInvoicing\Domain\Money;
use SureCartEuHelper\Modules\EInvoicing\Workflow\IdempotencyKey;
use SureCartEuHelper\Modules\EInvoicing\Workflow\DocumentValidator;
use SureCartEuHelper\Modules\EInvoicing\Providers\Storecove\StorecoveDocumentMapper;

$pass = 0;
$fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) {
		++$pass;
		echo "  PASS  {$label}\n";
	} else {
		++$fail;
		echo "  FAIL  {$label} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

echo "State machine:\n";
check( 'draft->mapped allowed', DocumentStatus::can_transition( 'draft', 'mapped' ), true );
check( 'draft->submitted blocked', DocumentStatus::can_transition( 'draft', 'submitted' ), false );
check( 'queued->submitted allowed', DocumentStatus::can_transition( 'queued', 'submitted' ), true );
check( 'submitted->delivered allowed', DocumentStatus::can_transition( 'submitted', 'delivered' ), true );
check( 'failed->queued allowed', DocumentStatus::can_transition( 'failed', 'queued' ), true );
check( 'delivered terminal', DocumentStatus::can_transition( 'delivered', 'queued' ), false );
check( 'submitted is sent', DocumentStatus::is_sent_or_beyond( 'submitted' ), true );
check( 'validated is not sent', DocumentStatus::is_sent_or_beyond( 'validated' ), false );

echo "Idempotency keys:\n";
check( 'invoice key', IdempotencyKey::for_invoice( 'ord_1', 'sandbox' ), 'inv:sandbox:ord_1' );
check( 'credit note key', IdempotencyKey::for_credit_note( 'ord_1', 're_9', 'production' ), 'cn:production:re_9' );
check( 'full credit note key', IdempotencyKey::for_credit_note( 'ord_1', null, 'sandbox' ), 'cn:sandbox:full:ord_1' );

echo "Money:\n";
check( 'format 1999 EUR', Money::format( 1999, 'EUR' ), '19.99 EUR' );
check( 'format JPY zero-decimal', Money::format( 500, 'JPY' ), '500 JPY' );

// A balanced sample invoice: net 1000, 19% tax = 190, gross 1190.
function sample_invoice() {
	$doc            = new Document();
	$doc->type      = DocumentType::INVOICE;
	$doc->number    = 'INV-00001';
	$doc->issue_date = '2026-06-22';
	$doc->currency  = 'EUR';
	$doc->merchant  = Document::party( array( 'name' => 'Acme GmbH', 'country' => 'DE' ) );
	$doc->customer  = Document::party( array( 'name' => 'Jane Buyer', 'country' => 'FR', 'email' => 'jane@example.com' ) );
	$doc->lines     = array(
		Document::line( array( 'source_ref' => 'li_1', 'description' => 'Widget', 'quantity' => 1, 'unit_price' => 1000, 'line_net' => 1000, 'tax_rate_percent' => 19, 'tax_category' => 'standard' ) ),
	);
	$doc->tax_lines = array(
		Document::tax_line( array( 'rate_percent' => 19, 'category' => 'standard', 'taxable_base' => 1000, 'tax_amount' => 190 ) ),
	);
	$doc->totals = array( 'net' => 1000, 'tax' => 190, 'gross' => 1190 );
	return $doc;
}

echo "Validator:\n";
check( 'balanced invoice valid', DocumentValidator::validate( sample_invoice() ), array() );

$bad         = sample_invoice();
$bad->totals = array( 'net' => 1000, 'tax' => 190, 'gross' => 1300 ); // gross != net+tax
check( 'unbalanced gross flagged', count( DocumentValidator::validate( $bad ) ) > 0, true );

$nomerch           = sample_invoice();
$nomerch->merchant = Document::party( array( 'name' => '', 'country' => '' ) );
check( 'missing merchant flagged', count( DocumentValidator::validate( $nomerch ) ) > 0, true );

$cn                       = sample_invoice();
$cn->type                 = DocumentType::CREDIT_NOTE;
$cn->original_document_id = null;
check( 'credit note without invoice link flagged', count( DocumentValidator::validate( $cn ) ) > 0, true );

echo "Storecove payload mapper:\n";
$body = ( new StorecoveDocumentMapper() )->to_submission( sample_invoice(), 42, 'tok-123' );
check( 'legalEntityId set', $body['legalEntityId'], 42 );
check( 'idempotencyGuid set', $body['idempotencyGuid'], 'tok-123' );
check( 'documentType invoice', $body['document']['documentType'], 'invoice' );
check( 'amountIncludingTax decimal', $body['document']['invoice']['amountIncludingTax'], 11.9 );
check( 'line amountExcludingTax decimal', $body['document']['invoice']['invoiceLines'][0]['amountExcludingTax'], 10.0 );
check( 'tax subtotal percentage', $body['document']['invoice']['taxSubtotals'][0]['percentage'], 19.0 );
check( 'routing falls back to email', $body['routing']['emails'][0], 'jane@example.com' );

$cnbody = ( new StorecoveDocumentMapper() )->to_submission( sample_invoice_credit(), 42, 'tok-9' );
function sample_invoice_credit() {
	$d       = sample_invoice();
	$d->type = DocumentType::CREDIT_NOTE;
	return $d;
}
check( 'credit note documentType', $cnbody['document']['documentType'], 'creditnote' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
