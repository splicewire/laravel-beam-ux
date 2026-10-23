<?php

namespace Splicewire\Beam\Ux\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionParameter;
use Splicewire\Beam\Ux\Data\FileDraftDiagnosticCode;
use Splicewire\Beam\Ux\Data\FileDraftDiagnosticData;
use Splicewire\Beam\Ux\Data\FileInspectionData;
use Splicewire\Beam\Ux\Data\FileReconcileResultData;

class FileDraftProjectionTest extends TestCase
{
    public function test_compile_failure_is_declared_optional_safe_feedback_with_actual_pointers(): void
    {
        $diagnostic = FileDraftDiagnosticData::compileFailed();
        $result = new FileReconcileResultData('drafted-with-compile-error', $diagnostic->message, 'entry', 'retained-d3', 'published-p1', $diagnostic);
        self::assertSame('compile-failed', $diagnostic->code->value);
        self::assertNotNull($result->diagnostic);
        self::assertSame(FileDraftDiagnosticCode::CompileFailed, $result->diagnostic->code);
        self::assertSame('Draft retained; compilation failed. Published content is unchanged. Review the draft before publication.', $result->message);
        self::assertSame('retained-d3', $result->headVersion);
        self::assertSame('published-p1', $result->publishedVersion);
        $parameter = new ReflectionParameter([FileReconcileResultData::class, '__construct'], 'diagnostic');
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(FileDraftDiagnosticData::class, $type->getName());
        self::assertTrue($parameter->allowsNull());
        self::assertNull($parameter->getDefaultValue());
    }

    public function test_existing_theme_result_and_inspection_constructor_remain_compatible(): void
    {
        $result = new FileReconcileResultData('drafted', 'Draft saved.', 'entry', 'd2', 'p1');
        $inspection = new FileInspectionData('member', 'entry', 'differs', 'A local color differs.');
        self::assertNull($result->diagnostic);
        self::assertNull($inspection->format);
        self::assertFalse($inspection->canRestore);
    }
}
