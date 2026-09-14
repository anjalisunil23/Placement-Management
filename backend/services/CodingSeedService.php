<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\CodingProblemBankModel;
use PMS\Models\CodingTestModel;

/**
 * Seed Easy / Medium / Hard bank problems and published practice + contest tests.
 */
final class CodingSeedService
{
    public const EASY_TEST = 'Easy Coding Practice';
    public const MEDIUM_TEST = 'Medium Coding Practice';
    public const HARD_TEST = 'Hard Coding Practice';
    public const WEEKLY_CONTEST = 'Weekly Coding Contest';
    public const MONTHLY_CONTEST = 'Monthly Coding Contest';

    private CodingProblemBankModel $bank;
    private CodingTestModel $tests;

    public function __construct(?CodingProblemBankModel $bank = null, ?CodingTestModel $tests = null)
    {
        $this->bank = $bank ?? new CodingProblemBankModel();
        $this->tests = $tests ?? new CodingTestModel();
    }

    public function ensureSeeded(): void
    {
        $this->ensureBank();
        $this->ensurePracticeTests();
        $this->ensureContests();
    }

    private function ensureBank(): void
    {
        $existing = [];
        foreach ($this->bank->listProblems() as $row) {
            $existing[strtolower(trim((string) ($row['title'] ?? '')))] = true;
        }
        foreach ($this->allProblems() as $problem) {
            $key = strtolower(trim((string) $problem['title']));
            if (isset($existing[$key])) {
                continue;
            }
            $this->bank->saveProblem($problem, null);
            $existing[$key] = true;
        }
    }

    private function ensurePracticeTests(): void
    {
        $byTitle = $this->testsByTitle();
        $groups = [
            self::EASY_TEST => ['Easy', 'Programming', 25, array_slice($this->problemsByDifficulty('Easy'), 0, 10)],
            self::MEDIUM_TEST => ['Medium', 'Data Structures', 35, array_slice($this->problemsByDifficulty('Medium'), 0, 10)],
            self::HARD_TEST => ['Hard', 'Algorithms', 45, array_slice($this->problemsByDifficulty('Hard'), 0, 10)],
        ];
        foreach ($groups as $title => [$difficulty, $category, $duration, $items]) {
            if (isset($byTitle[strtolower($title)])) {
                continue;
            }
            $this->tests->saveNew($this->testPayload($title, $category, $difficulty, $duration, $items, 'none'));
        }
    }

    private function ensureContests(): void
    {
        $byTitle = $this->testsByTitle();
        $now = new \DateTimeImmutable('now');
        if (!isset($byTitle[strtolower(self::WEEKLY_CONTEST)])) {
            $items = array_merge(
                array_slice($this->problemsByDifficulty('Easy'), 0, 3),
                array_slice($this->problemsByDifficulty('Medium'), 0, 2)
            );
            $payload = $this->testPayload(self::WEEKLY_CONTEST, 'Programming', 'Medium', 40, $items, 'weekly');
            $payload['contestWeekday'] = (int) $now->format('N');
            $this->tests->saveNew($payload);
        }
        if (!isset($byTitle[strtolower(self::MONTHLY_CONTEST)])) {
            $items = array_merge(
                array_slice($this->problemsByDifficulty('Medium'), 0, 2),
                array_slice($this->problemsByDifficulty('Hard'), 0, 3)
            );
            $payload = $this->testPayload(self::MONTHLY_CONTEST, 'Algorithms', 'Hard', 50, $items, 'monthly');
            $payload['contestMonthDay'] = min(28, (int) $now->format('j'));
            $this->tests->saveNew($payload);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function testsByTitle(): array
    {
        $out = [];
        foreach ($this->tests->findAll([], 500, 0, ['createdAt' => -1]) as $row) {
            $out[strtolower(trim((string) ($row['title'] ?? '')))] = $row;
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function testPayload(string $title, string $category, string $difficulty, int $duration, array $items, string $contestType): array
    {
        return [
            'title' => $title,
            'description' => $contestType === 'none'
                ? "{$difficulty} coding practice — 10 problems for placement preparation."
                : "Published {$contestType} coding contest. Students can attempt it on the scheduled day.",
            'category' => $category,
            'difficulty' => $difficulty,
            'duration' => $duration,
            'status' => 'published',
            'contestType' => $contestType,
            'departmentId' => '',
            'instructions' => [
                'Read each problem carefully.',
                'Select the programming language before running or submitting.',
                'Your code is evaluated against sample and hidden test cases.',
                'Do not refresh the page during the test.',
                'Your result appears automatically after you finish.',
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function problemsByDifficulty(string $difficulty): array
    {
        return array_values(array_filter(
            $this->allProblems(),
            static fn (array $p) => ($p['difficulty'] ?? '') === $difficulty
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allProblems(): array
    {
        return array_merge($this->easyProblems(), $this->mediumProblems(), $this->hardProblems());
    }

    /**
     * @param array<int, array{0:string,1:string}> $hidden
     * @return array<string, mixed>
     */
    private function problem(
        string $difficulty,
        string $title,
        string $description,
        string $inputFormat,
        string $outputFormat,
        string $constraints,
        string $sampleIn,
        string $sampleOut,
        array $hidden,
        int $marks = 2
    ): array {
        $cases = [
            ['id' => 's1', 'label' => 'Sample Test Case', 'input' => $sampleIn, 'expected' => $sampleOut, 'sample' => true],
        ];
        foreach (array_values($hidden) as $i => $pair) {
            $cases[] = [
                'id' => 'h' . ($i + 1),
                'input' => $pair[0],
                'expected' => $pair[1],
                'sample' => false,
            ];
        }
        return [
            'title' => $title,
            'description' => $description,
            'inputFormat' => $inputFormat,
            'outputFormat' => $outputFormat,
            'constraints' => $constraints,
            'examples' => [['input' => $sampleIn, 'output' => $sampleOut]],
            'starterCode' => ['Python' => "# Write your solution\n"],
            'testCases' => $cases,
            'marks' => $marks,
            'difficulty' => $difficulty,
            'category' => $difficulty === 'Hard' ? 'Algorithms' : ($difficulty === 'Medium' ? 'Data Structures' : 'Programming'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function easyProblems(): array
    {
        return [
            $this->problem('Easy', 'Reverse a String', 'Print the reverse of the given string.', 'A single line string S.', 'The reversed string.', '1 ≤ |S| ≤ 1000', 'hello', 'olleh', [['world', 'dlrow'], ['a', 'a']]),
            $this->problem('Easy', 'Sum of Two Numbers', 'Print the sum of two integers.', 'Two integers A and B.', 'A + B', '−1e9 ≤ A,B ≤ 1e9', '3 5', '8', [['10 -2', '8'], ['0 0', '0']]),
            $this->problem('Easy', 'Even or Odd', 'Print Even if N is even, otherwise Odd.', 'One integer N.', 'Even or Odd', '−1e9 ≤ N ≤ 1e9', '7', 'Odd', [['12', 'Even'], ['0', 'Even']]),
            $this->problem('Easy', 'Maximum of Three', 'Print the largest of three integers.', 'Three integers A B C.', 'The maximum value.', '−1e9 ≤ A,B,C ≤ 1e9', '4 9 2', '9', [['-1 -8 -3', '-1'], ['5 5 5', '5']]),
            $this->problem('Easy', 'Factorial', 'Print N! (N factorial).', 'One integer N.', 'N!', '0 ≤ N ≤ 12', '5', '120', [['0', '1'], ['7', '5040']]),
            $this->problem('Easy', 'Count Vowels', 'Count vowels a,e,i,o,u in the string (case-insensitive).', 'A single line string S.', 'The vowel count.', '1 ≤ |S| ≤ 1000', 'Hello', '2', [['AEIOU', '5'], ['xyz', '0']]),
            $this->problem('Easy', 'Palindrome Check', 'Print YES if S is a palindrome, else NO. Ignore case.', 'A single line string S.', 'YES or NO', '1 ≤ |S| ≤ 1000', 'Level', 'YES', [['abc', 'NO'], ['abba', 'YES']]),
            $this->problem('Easy', 'Sum of Digits', 'Print the sum of digits of N.', 'One non-negative integer N.', 'Sum of digits.', '0 ≤ N ≤ 1e12', '123', '6', [['0', '0'], ['999', '27']]),
            $this->problem('Easy', 'Nth Triangle Number', 'Print 1+2+...+N.', 'One integer N.', 'The sum.', '1 ≤ N ≤ 10000', '5', '15', [['1', '1'], ['10', '55']]),
            $this->problem('Easy', 'Absolute Difference', 'Print |A-B|.', 'Two integers A and B.', 'The absolute difference.', '−1e9 ≤ A,B ≤ 1e9', '8 3', '5', [['3 8', '5'], ['-4 -4', '0']]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function mediumProblems(): array
    {
        return [
            $this->problem('Medium', 'Nth Fibonacci', 'Print the Nth Fibonacci number. F(1)=1, F(2)=1.', 'One integer N.', 'F(N)', '1 ≤ N ≤ 40', '6', '8', [['1', '1'], ['10', '55']]),
            $this->problem('Medium', 'Prime Check', 'Print YES if N is prime, else NO.', 'One integer N.', 'YES or NO', '1 ≤ N ≤ 1e6', '13', 'YES', [['1', 'NO'], ['9', 'NO']]),
            $this->problem('Medium', 'Second Largest', 'Print the second largest distinct number. If none, print -1.', 'First line N. Second line N integers.', 'The second largest distinct value or -1.', '2 ≤ N ≤ 1000', "5\n4 9 1 9 2", '4', [["3\n5 5 5", '-1'], ["4\n1 2 3 4", '3']]),
            $this->problem('Medium', 'Reverse Array', 'Print the array in reverse order.', 'First line N. Second line N integers.', 'N integers reversed, space-separated.', '1 ≤ N ≤ 1000', "4\n1 2 3 4", '4 3 2 1', [["1\n9", '9'], ["3\n-1 0 5", '5 0 -1']]),
            $this->problem('Medium', 'Count Occurrences', 'Count how many times X appears in the array.', 'First line N X. Second line N integers.', 'The count.', '1 ≤ N ≤ 1000', "5 2\n1 2 2 3 2", '3', [["4 7\n1 2 3 4", '0'], ["3 1\n1 1 1", '3']]),
            $this->problem('Medium', 'GCD of Two Numbers', 'Print gcd(A,B).', 'Two integers A and B.', 'The GCD.', '1 ≤ A,B ≤ 1e9', '12 18', '6', [['7 13', '1'], ['0 5', '5']], 3),
            $this->problem('Medium', 'Anagram Check', 'Print YES if the two words are anagrams (case-insensitive), else NO.', 'Two space-separated words.', 'YES or NO', '1 ≤ |word| ≤ 200', 'listen silent', 'YES', [['Hello World', 'NO'], ['Aa aA', 'YES']]),
            $this->problem('Medium', 'Missing Number', 'Array contains N-1 distinct numbers from 1..N. Print the missing number.', 'First line N. Second line N-1 integers.', 'The missing number.', '2 ≤ N ≤ 1000', "5\n1 2 4 5", '3', [["3\n1 3", '2'], ["4\n2 3 4", '1']]),
            $this->problem('Medium', 'Pair With Target Sum', 'Print YES if two numbers add to T, else NO.', 'First line N T. Second line N integers.', 'YES or NO', '2 ≤ N ≤ 1000', "4 9\n2 7 11 15", 'YES', [["3 10\n1 2 3", 'NO'], ["5 6\n1 2 3 4 5", 'YES']]),
            $this->problem('Medium', 'Remove Duplicates', 'Print unique numbers in first-seen order.', 'First line N. Second line N integers.', 'Unique integers space-separated.', '1 ≤ N ≤ 1000', "6\n1 2 2 3 1 4", '1 2 3 4', [["4\n5 5 5 5", '5'], ["3\n3 2 1", '3 2 1']]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function hardProblems(): array
    {
        return [
            $this->problem('Hard', 'Balanced Parentheses', 'Print YES if brackets ()[]{} are balanced, else NO.', 'A single line of brackets.', 'YES or NO', '1 ≤ |S| ≤ 1000', '()[]{}', 'YES', [['(]', 'NO'], ['({[]})', 'YES']], 3),
            $this->problem('Hard', 'Maximum Subarray Sum', 'Print the maximum contiguous subarray sum (Kadane).', 'First line N. Second line N integers.', 'The maximum sum.', '1 ≤ N ≤ 1000', "5\n-2 1 -3 4 -1", '4', [["3\n-1 -2 -3", '-1'], ["4\n1 2 3 4", '10']], 3),
            $this->problem('Hard', 'Merge Sorted Arrays', 'Merge two sorted arrays into one sorted array.', 'Line 1: N M. Line 2: N integers. Line 3: M integers.', 'Merged sorted array.', '1 ≤ N,M ≤ 500', "3 3\n1 3 5\n2 4 6", '1 2 3 4 5 6', [["2 2\n1 2\n3 4", '1 2 3 4'], ["1 3\n0\n-2 -1 5", '-2 -1 0 5']], 3),
            $this->problem('Hard', 'Binary Search Index', 'Print 0-based index of X in a sorted array, or -1.', 'Line 1: N X. Line 2: N sorted integers.', 'The index or -1.', '1 ≤ N ≤ 1000', "5 7\n1 3 5 7 9", '3', [["4 2\n1 3 5 7", '-1'], ["3 1\n1 2 3", '0']], 3),
            $this->problem('Hard', 'Next Greater Element', 'For each value print the next greater to its right, or -1.', 'First line N. Second line N integers.', 'N integers.', '1 ≤ N ≤ 1000', "4\n2 1 2 4", '4 2 4 -1', [["3\n5 4 3", '-1 -1 -1'], ["3\n1 2 3", '2 3 -1']], 3),
            $this->problem('Hard', 'Longest Word', 'Print the longest word. If tie, the first one.', 'A single line of words.', 'The longest word.', '1 ≤ words ≤ 100', 'I love programming', 'programming', [['aa bbb cc', 'bbb'], ['one two six', 'one']], 3),
            $this->problem('Hard', 'Rotate Array Right', 'Rotate the array K steps to the right.', 'Line 1: N K. Line 2: N integers.', 'Rotated array.', '1 ≤ N ≤ 1000, 0 ≤ K ≤ 1e6', "5 2\n1 2 3 4 5", '4 5 1 2 3', [["4 1\n10 20 30 40", '40 10 20 30'], ["3 3\n1 2 3", '1 2 3']], 3),
            $this->problem('Hard', 'Most Frequent Number', 'Print the most frequent number. If tie, the smaller number.', 'First line N. Second line N integers.', 'The mode.', '1 ≤ N ≤ 1000', "6\n1 2 2 3 3 3", '3', [["4\n4 1 4 1", '1'], ["5\n7 7 8 8 8", '8']], 3),
            $this->problem('Hard', 'Matrix Diagonal Sum', 'Print the sum of both main diagonals of an N×N matrix. Center counted once.', 'First line N. Then N lines of N integers.', 'The diagonal sum.', '1 ≤ N ≤ 50', "3\n1 2 3\n4 5 6\n7 8 9", '25', [["1\n4", '4'], ["2\n1 2\n3 4", '10']], 3),
            $this->problem('Hard', 'LCM of Two Numbers', 'Print lcm(A,B).', 'Two integers A and B.', 'The LCM.', '1 ≤ A,B ≤ 1e6', '4 6', '12', [['7 3', '21'], ['5 5', '5']], 3),
        ];
    }
}
