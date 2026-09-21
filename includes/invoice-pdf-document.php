<?php
/**
 * ShopInnKart - TCPDF subclass used for invoice PDFs.
 *
 * Loaded by invoice_pdf_bootstrap() only, which requires tcpdf.php first —
 * this file cannot be included on its own.
 *
 * Page 1 carries the full letterhead inside the content flow. Continuation
 * pages get a slim identifying strip instead, so a two-page invoice still
 * shows its number on every sheet without repeating the whole masthead.
 */

declare(strict_types=1);

if (!class_exists('TCPDF', false)) {
    throw new RuntimeException('invoice-pdf-document.php requires TCPDF to be loaded first.');
}

if (!class_exists('SikInvoicePdf', false)) {
    final class SikInvoicePdf extends TCPDF
    {
        public string $sikInvoiceNumber = '';
        public string $sikOrderNumber = '';
        public string $sikCompanyName = '';
        public string $sikFooterNote = '';

        /** Slim strip on continuation pages; page 1's masthead is in the flow. */
        public function Header(): void // phpcs:ignore
        {
            if ($this->getPage() <= 1) {
                return;
            }

            $this->SetY(8);
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(107, 114, 128);
            $this->Cell(0, 5, $this->sikCompanyName . '  ·  Tax Invoice ' . $this->sikInvoiceNumber, 0, 1, 'L');

            $this->SetDrawColor(229, 231, 235);
            $this->Line(15, $this->GetY() + 1, 195, $this->GetY() + 1);
            $this->SetTextColor(17, 24, 39);
        }

        public function Footer(): void // phpcs:ignore
        {
            $this->SetY(-15);
            $this->SetDrawColor(229, 231, 235);
            $this->Line(15, $this->GetY(), 195, $this->GetY());

            $this->SetY(-12);
            $this->SetFont('dejavusans', '', 7.5);
            $this->SetTextColor(107, 114, 128);

            $this->Cell(120, 5, $this->sikFooterNote, 0, 0, 'L');
            // getAliasNumPage()/getAliasNbPages() resolve after the last page
            // is written, which is what makes "Page 1 of 3" correct.
            $this->Cell(60, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');

            $this->SetTextColor(17, 24, 39);
        }
    }
}
