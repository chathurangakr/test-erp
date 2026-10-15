<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\SupplierPayment;
use App\Support\SourceDocument;
use Tests\TestCase;

class SourceDocumentTest extends TestCase
{
    public function test_a_known_document_type_reads_as_words(): void
    {
        $this->assertSame('Supplier payment', SourceDocument::label(SupplierPayment::class));
        $this->assertSame('Production', SourceDocument::label(ProductionOrder::class));
    }

    public function test_an_unlisted_type_still_reads_as_words_not_a_class_name(): void
    {
        $this->assertSame('Lot consolidation', SourceDocument::label('App\\Models\\LotConsolidation'));
    }

    public function test_no_source_is_named_by_the_caller(): void
    {
        $this->assertSame('Manual', SourceDocument::label(null));
        $this->assertSame('Manual journal', SourceDocument::label(null, 'Manual journal'));
    }

    public function test_the_name_follows_the_screen_language(): void
    {
        app()->setLocale('si');

        $this->assertSame('සැපයුම්කරු ගෙවීම', SourceDocument::label(SupplierPayment::class));
    }
}
