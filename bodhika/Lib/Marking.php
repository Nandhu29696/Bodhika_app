<?php
/**
 * Lib/Marking.php — Shared exam marking-scheme resolver.
 *
 * Single source of truth for turning an exam's marking-scheme columns
 * (MarkingScheme / TotalMarks / MarksPerQuestion / NegativeMarks) into the
 * actual "marks per correct answer" + "total marks" used both when
 * previewing the scheme on the exam edit form (exam/manage.php) and when
 * scoring a real attempt (exam/submit.php) — so the two can never drift
 * apart from each other.
 *
 * Backward compatible by design: an exam with no marking-scheme columns set
 * (or a database that hasn't run migration_v38.sql yet) resolves to the
 * exact same "100 marks, no negative marking" behaviour that exam/submit.php
 * hardcoded before this scheme existed.
 */
final class Marking
{
    public const DEFAULT_TOTAL_MARKS = 100.0;

    /**
     * @param array $exam         Row (or partial row) from examinfo — reads
     *                            MarkingScheme, TotalMarks, MarksPerQuestion,
     *                            NegativeMarks if present.
     * @param int   $numQuestions Number of questions being scored (the
     *                            exam's NumOfQuestions, or the count of
     *                            questions actually presented in an attempt).
     * @return array{totalMarks:float,marksPerQuestion:float,negativeMarks:float,scheme:string}
     */
    public static function resolve(array $exam, int $numQuestions): array
    {
        $numQuestions  = max(0, $numQuestions);
        $scheme        = ($exam['MarkingScheme'] ?? 'Dynamic') === 'Fixed' ? 'Fixed' : 'Dynamic';
        $negativeMarks = max(0.0, (float)($exam['NegativeMarks'] ?? 0));

        if ($scheme === 'Fixed') {
            $perQ = (float)($exam['MarksPerQuestion'] ?? 0);
            if ($perQ <= 0) {
                // Fixed selected but no value set — fall back to Dynamic so
                // scoring never silently zeroes out.
                $scheme = 'Dynamic';
                $total  = (float)($exam['TotalMarks'] ?? self::DEFAULT_TOTAL_MARKS) ?: self::DEFAULT_TOTAL_MARKS;
                $perQ   = $numQuestions > 0 ? $total / $numQuestions : 0.0;
            } else {
                $total = round($perQ * $numQuestions, 2);
            }
        } else {
            $total = (float)($exam['TotalMarks'] ?? self::DEFAULT_TOTAL_MARKS);
            if ($total <= 0) $total = self::DEFAULT_TOTAL_MARKS;
            $perQ  = $numQuestions > 0 ? $total / $numQuestions : 0.0;
        }

        return [
            'totalMarks'       => $total,
            'marksPerQuestion' => $perQ,
            'negativeMarks'    => $negativeMarks,
            'scheme'           => $scheme,
        ];
    }

    /** Human-readable summary, e.g. "+4 / -1, 720 marks total" — used in admin lists. */
    public static function summarize(array $exam, int $numQuestions): string
    {
        $m = self::resolve($exam, $numQuestions);
        $perQ = rtrim(rtrim(number_format($m['marksPerQuestion'], 2), '0'), '.');
        $neg  = rtrim(rtrim(number_format($m['negativeMarks'], 2), '0'), '.');
        $tot  = rtrim(rtrim(number_format($m['totalMarks'], 2), '0'), '.');
        return "+{$perQ} / -{$neg} per question, {$tot} marks total";
    }
}
