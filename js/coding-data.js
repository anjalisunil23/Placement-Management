/* PlaceHub — coding practice fixtures (UI-facing fields + hidden evaluators) */
(function (global) {
  const LANGUAGES = ['Python', 'Java', 'C', 'C++', 'JavaScript'];

  function defaultStarters(pythonBody) {
    return {
      Python: pythonBody,
      Java: 'import java.util.*;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    // Write your solution\n  }\n}\n',
      C: '#include <stdio.h>\nint main() {\n  // Write your solution\n  return 0;\n}\n',
      'C++': '#include <bits/stdc++.h>\nusing namespace std;\nint main() {\n  ios::sync_with_stdio(false);\n  cin.tie(nullptr);\n  // Write your solution\n  return 0;\n}\n',
      JavaScript: "const input = require('fs').readFileSync(0, 'utf8').trim();\n// Write your solution\n",
    };
  }

  function q(opts) {
    return {
      id: opts.id,
      title: opts.title,
      description: opts.description,
      inputFormat: opts.inputFormat,
      outputFormat: opts.outputFormat,
      constraints: opts.constraints,
      examples: opts.examples,
      starterCode: defaultStarters(opts.python),
      testCases: opts.testCases,
      keywords: opts.keywords,
      marks: opts.marks,
      solver: opts.solver,
    };
  }

  const TESTS = [
    {
      id: 'coding-fundamentals',
      title: 'Programming Fundamentals — Basics',
      category: 'Programming',
      difficulty: 'Easy',
      questions: 5,
      duration: 20,
      marks: 10,
      description: 'Practice variables, loops, conditions and basic programming concepts.',
      instructions: [
        'Read each problem carefully.',
        'Select the programming language before submitting.',
        'Your code will be evaluated against test cases.',
        'Make sure your solution handles edge cases.',
        'Do not refresh the page during the test.',
      ],
      items: [
        q({
          id: 'pf-q1',
          title: 'Reverse a String',
          description: 'Write a program that reverses the given string.',
          inputFormat: 'A single line containing a string S.',
          outputFormat: 'Print the reversed string.',
          constraints: '1 ≤ length of string ≤ 1000',
          examples: [{ input: 'hello', output: 'olleh' }],
          python: 's = input()\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: 'hello', expected: 'olleh', sample: true },
            { id: 'h1', input: 'world', expected: 'dlrow', sample: false },
            { id: 'h2', input: 'a', expected: 'a', sample: false },
          ],
          keywords: { Python: ['[::-1]', 'reversed', 'reverse'], Java: ['reverse', 'StringBuilder'], C: ['strlen'], 'C++': ['reverse', 'rbegin'], JavaScript: ['reverse', 'split'] },
          solver: (raw) => [...String(raw).trim()].reverse().join(''),
        }),
        q({
          id: 'pf-q2',
          title: 'Sum of Two Numbers',
          description: 'Read two integers and print their sum.',
          inputFormat: 'Two integers A and B separated by a space.',
          outputFormat: 'Print a single integer — the sum of A and B.',
          constraints: '−10^9 ≤ A, B ≤ 10^9',
          examples: [{ input: '3 5', output: '8' }],
          python: 'a, b = map(int, input().split())\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '3 5', expected: '8', sample: true },
            { id: 'h1', input: '10 -2', expected: '8', sample: false },
            { id: 'h2', input: '0 0', expected: '0', sample: false },
          ],
          keywords: { Python: ['print(a', 'a + b', 'a+b'], Java: ['println', 'a + b'], C: ['printf', 'a + b'], 'C++': ['cout', 'a + b'], JavaScript: ['console.log', 'a + b'] },
          solver: (raw) => {
            const [a, b] = String(raw).trim().split(/\s+/).map(Number);
            return String(a + b);
          },
        }),
        q({
          id: 'pf-q3',
          title: 'Even or Odd',
          description: 'Determine whether the given integer is even or odd.',
          inputFormat: 'A single integer N.',
          outputFormat: 'Print Even if N is even, otherwise print Odd.',
          constraints: '−10^9 ≤ N ≤ 10^9',
          examples: [{ input: '7', output: 'Odd' }],
          python: 'n = int(input())\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '7', expected: 'Odd', sample: true },
            { id: 'h1', input: '12', expected: 'Even', sample: false },
            { id: 'h2', input: '0', expected: 'Even', sample: false },
          ],
          keywords: { Python: ['%', 'Even', 'Odd'], Java: ['%', 'Even', 'Odd'], C: ['%', 'Even', 'Odd'], 'C++': ['%', 'Even', 'Odd'], JavaScript: ['%', 'Even', 'Odd'] },
          solver: (raw) => (Number(String(raw).trim()) % 2 === 0 ? 'Even' : 'Odd'),
        }),
        q({
          id: 'pf-q4',
          title: 'Maximum of Three Numbers',
          description: 'Find the largest among three integers.',
          inputFormat: 'Three integers A, B and C separated by spaces.',
          outputFormat: 'Print the maximum value.',
          constraints: '−10^9 ≤ A, B, C ≤ 10^9',
          examples: [{ input: '4 9 2', output: '9' }],
          python: 'a, b, c = map(int, input().split())\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '4 9 2', expected: '9', sample: true },
            { id: 'h1', input: '-1 -8 -3', expected: '-1', sample: false },
            { id: 'h2', input: '5 5 5', expected: '5', sample: false },
          ],
          keywords: { Python: ['max'], Java: ['Math.max', 'max'], C: ['if'], 'C++': ['max'], JavaScript: ['Math.max', 'max'] },
          solver: (raw) => String(Math.max(...String(raw).trim().split(/\s+/).map(Number))),
        }),
        q({
          id: 'pf-q5',
          title: 'Count Vowels',
          description: 'Count the number of vowels (a, e, i, o, u) in the given string. Ignore case.',
          inputFormat: 'A single line containing a string S.',
          outputFormat: 'Print the count of vowels.',
          constraints: '1 ≤ length of string ≤ 1000',
          examples: [{ input: 'education', output: '5' }],
          python: 's = input()\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: 'education', expected: '5', sample: true },
            { id: 'h1', input: 'rhythm', expected: '0', sample: false },
            { id: 'h2', input: 'AEIOU', expected: '5', sample: false },
          ],
          keywords: { Python: ['aeiou', 'count', 'in'], Java: ['aeiou', 'indexOf'], C: ['aeiou', 'strchr'], 'C++': ['aeiou'], JavaScript: ['aeiou', 'match'] },
          solver: (raw) => String((String(raw).trim().match(/[aeiou]/gi) || []).length),
        }),
      ],
    },
    {
      id: 'coding-python',
      title: 'Python Programming — Beginner',
      category: 'Python',
      difficulty: 'Easy',
      questions: 5,
      duration: 20,
      marks: 10,
      description: 'Practice Python fundamentals, strings, lists, loops and functions.',
      instructions: [
        'Read each problem carefully.',
        'Select the programming language before submitting.',
        'Your code will be evaluated against test cases.',
        'Make sure your solution handles edge cases.',
        'Do not refresh the page during the test.',
      ],
      items: [
        q({
          id: 'py-q1',
          title: 'Sum of a List',
          description: 'Read a line of integers and print their sum.',
          inputFormat: 'A single line of space-separated integers.',
          outputFormat: 'Print the sum of the integers.',
          constraints: '1 ≤ n ≤ 1000, −10^6 ≤ each value ≤ 10^6',
          examples: [{ input: '1 2 3 4 5', output: '15' }],
          python: 'nums = list(map(int, input().split()))\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '1 2 3 4 5', expected: '15', sample: true },
            { id: 'h1', input: '10', expected: '10', sample: false },
            { id: 'h2', input: '-1 1 0', expected: '0', sample: false },
          ],
          keywords: { Python: ['sum', 'for'], Java: ['+=', 'split'], C: ['+=', 'scanf'], 'C++': ['accumulate', '+='] , JavaScript: ['reduce', 'split'] },
          solver: (raw) => String(String(raw).trim().split(/\s+/).map(Number).reduce((a, b) => a + b, 0)),
        }),
        q({
          id: 'py-q2',
          title: 'Palindrome Check',
          description: 'Check whether the given string is a palindrome. Ignore case.',
          inputFormat: 'A single line containing a string S.',
          outputFormat: 'Print Yes if S is a palindrome, otherwise print No.',
          constraints: '1 ≤ length of string ≤ 1000',
          examples: [{ input: 'madam', output: 'Yes' }],
          python: 's = input()\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: 'madam', expected: 'Yes', sample: true },
            { id: 'h1', input: 'hello', expected: 'No', sample: false },
            { id: 'h2', input: 'Level', expected: 'Yes', sample: false },
          ],
          keywords: { Python: ['[::-1]', 'reversed'], Java: ['reverse', 'equalsIgnoreCase'], C: ['strlen'], 'C++': ['reverse'], JavaScript: ['reverse', 'toLowerCase'] },
          solver: (raw) => {
            const s = String(raw).trim().toLowerCase();
            return s === [...s].reverse().join('') ? 'Yes' : 'No';
          },
        }),
        q({
          id: 'py-q3',
          title: 'Factorial',
          description: 'Compute N factorial (N!).',
          inputFormat: 'A single integer N.',
          outputFormat: 'Print N!.',
          constraints: '0 ≤ N ≤ 12',
          examples: [{ input: '5', output: '120' }],
          python: 'n = int(input())\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '5', expected: '120', sample: true },
            { id: 'h1', input: '0', expected: '1', sample: false },
            { id: 'h2', input: '7', expected: '5040', sample: false },
          ],
          keywords: { Python: ['for', 'range', 'factorial', '*='], Java: ['for', '*='], C: ['for', '*='], 'C++': ['for', '*='], JavaScript: ['for', '*='] },
          solver: (raw) => {
            const n = Number(String(raw).trim());
            let f = 1;
            for (let i = 2; i <= n; i += 1) f *= i;
            return String(f);
          },
        }),
        q({
          id: 'py-q4',
          title: 'Character Frequency',
          description: 'Count how many times character C appears in string S.',
          inputFormat: 'First token is the string S. Second token is the character C.',
          outputFormat: 'Print the frequency of C in S.',
          constraints: '1 ≤ |S| ≤ 1000, C is a single character',
          examples: [{ input: 'banana a', output: '3' }],
          python: 's, c = input().split()\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: 'banana a', expected: '3', sample: true },
            { id: 'h1', input: 'hello z', expected: '0', sample: false },
            { id: 'h2', input: 'mississippi s', expected: '4', sample: false },
          ],
          keywords: { Python: ['count'], Java: ['charAt', '=='], C: ['for'], 'C++': ['count'], JavaScript: ['split', 'filter'] },
          solver: (raw) => {
            const parts = String(raw).trim().split(/\s+/);
            const c = parts.pop();
            const s = parts.join(' ');
            return String([...s].filter((ch) => ch === c).length);
          },
        }),
        q({
          id: 'py-q5',
          title: 'Swap Two Numbers',
          description: 'Swap two integers and print them in swapped order.',
          inputFormat: 'Two integers A and B separated by a space.',
          outputFormat: 'Print B and A separated by a space.',
          constraints: '−10^9 ≤ A, B ≤ 10^9',
          examples: [{ input: '10 20', output: '20 10' }],
          python: 'a, b = map(int, input().split())\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '10 20', expected: '20 10', sample: true },
            { id: 'h1', input: '0 1', expected: '1 0', sample: false },
            { id: 'h2', input: '-3 -3', expected: '-3 -3', sample: false },
          ],
          keywords: { Python: ['print(b', 'b, a'], Java: ['temp'], C: ['temp'], 'C++': ['swap'], JavaScript: ['[b, a]', 'b, a'] },
          solver: (raw) => {
            const [a, b] = String(raw).trim().split(/\s+/);
            return `${b} ${a}`;
          },
        }),
      ],
    },
    {
      id: 'coding-ds',
      title: 'Data Structures — Basics',
      category: 'Data Structures',
      difficulty: 'Medium',
      questions: 5,
      duration: 25,
      marks: 10,
      description: 'Practice arrays, strings, stacks, queues and basic data structures.',
      instructions: [
        'Read each problem carefully.',
        'Select the programming language before submitting.',
        'Your code will be evaluated against test cases.',
        'Make sure your solution handles edge cases.',
        'Do not refresh the page during the test.',
      ],
      items: [
        q({
          id: 'ds-q1',
          title: 'Second Largest',
          description: 'Find the second largest distinct value in an array of integers.',
          inputFormat: 'A single line of space-separated integers.',
          outputFormat: 'Print the second largest distinct integer.',
          constraints: '2 ≤ n ≤ 1000',
          examples: [{ input: '10 20 5 8 20', output: '10' }],
          python: 'arr = list(map(int, input().split()))\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '10 20 5 8 20', expected: '10', sample: true },
            { id: 'h1', input: '3 1 2', expected: '2', sample: false },
            { id: 'h2', input: '9 9 8 8 7', expected: '8', sample: false },
          ],
          keywords: { Python: ['sorted', 'set', 'max'], Java: ['sort'], C: ['for'], 'C++': ['sort', 'unique'], JavaScript: ['sort', 'Set'] },
          solver: (raw) => {
            const uniq = [...new Set(String(raw).trim().split(/\s+/).map(Number))].sort((a, b) => b - a);
            return String(uniq[1]);
          },
        }),
        q({
          id: 'ds-q2',
          title: 'Balanced Parentheses',
          description: 'Check whether a string of parentheses is balanced.',
          inputFormat: 'A single line containing only ( and ) characters.',
          outputFormat: 'Print Yes if balanced, otherwise print No.',
          constraints: '1 ≤ length ≤ 1000',
          examples: [{ input: '(())', output: 'Yes' }],
          python: 's = input().strip()\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '(())', expected: 'Yes', sample: true },
            { id: 'h1', input: '(()', expected: 'No', sample: false },
            { id: 'h2', input: '()()', expected: 'Yes', sample: false },
          ],
          keywords: { Python: ['stack', 'append', 'pop'], Java: ['Stack', 'push'], C: ['count', 'for'], 'C++': ['stack'], JavaScript: ['push', 'pop'] },
          solver: (raw) => {
            let n = 0;
            for (const ch of String(raw).trim()) {
              if (ch === '(') n += 1;
              else if (ch === ')') n -= 1;
              if (n < 0) return 'No';
            }
            return n === 0 ? 'Yes' : 'No';
          },
        }),
        q({
          id: 'ds-q3',
          title: 'Reverse an Array',
          description: 'Print the array elements in reverse order.',
          inputFormat: 'A single line of space-separated integers.',
          outputFormat: 'Print the reversed array, space-separated.',
          constraints: '1 ≤ n ≤ 1000',
          examples: [{ input: '1 2 3 4', output: '4 3 2 1' }],
          python: 'arr = list(map(int, input().split()))\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '1 2 3 4', expected: '4 3 2 1', sample: true },
            { id: 'h1', input: '9', expected: '9', sample: false },
            { id: 'h2', input: '5 4 3', expected: '3 4 5', sample: false },
          ],
          keywords: { Python: ['[::-1]', 'reversed', 'reverse'], Java: ['reverse'], C: ['for'], 'C++': ['reverse'], JavaScript: ['reverse'] },
          solver: (raw) => String(raw).trim().split(/\s+/).reverse().join(' '),
        }),
        q({
          id: 'ds-q4',
          title: 'Remove Duplicates',
          description: 'Remove duplicate integers while preserving the first occurrence order.',
          inputFormat: 'A single line of space-separated integers.',
          outputFormat: 'Print the unique values in original order.',
          constraints: '1 ≤ n ≤ 1000',
          examples: [{ input: '1 2 1 3 2', output: '1 2 3' }],
          python: 'arr = list(map(int, input().split()))\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '1 2 1 3 2', expected: '1 2 3', sample: true },
            { id: 'h1', input: '4 4 4', expected: '4', sample: false },
            { id: 'h2', input: '8 7 8 6 7', expected: '8 7 6', sample: false },
          ],
          keywords: { Python: ['dict', 'set', 'not in'], Java: ['LinkedHashSet', 'Set'], C: ['for'], 'C++': ['set', 'unordered'], JavaScript: ['Set'] },
          solver: (raw) => {
            const seen = new Set();
            const out = [];
            String(raw).trim().split(/\s+/).forEach((v) => {
              if (!seen.has(v)) {
                seen.add(v);
                out.push(v);
              }
            });
            return out.join(' ');
          },
        }),
        q({
          id: 'ds-q5',
          title: 'Binary Search',
          description: 'A sorted array is given on the first line. The target is on the second line. Print the 0-based index of the target, or -1 if it is missing.',
          inputFormat: 'Line 1: space-separated sorted integers.\nLine 2: the target integer.',
          outputFormat: 'Print the index, or -1.',
          constraints: '1 ≤ n ≤ 1000',
          examples: [{ input: '1 3 5 7 9\n5', output: '2' }],
          python: 'arr = list(map(int, input().split()))\ntarget = int(input())\n# Write your solution\n',
          marks: 2,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '1 3 5 7 9\n5', expected: '2', sample: true },
            { id: 'h1', input: '1 3 5 7 9\n8', expected: '-1', sample: false },
            { id: 'h2', input: '2 4 6\n2', expected: '0', sample: false },
          ],
          keywords: { Python: ['bisect', 'while', 'mid', 'index'], Java: ['binarySearch', 'mid'], C: ['mid', 'while'], 'C++': ['lower_bound', 'mid'], JavaScript: ['while', 'mid', 'indexOf'] },
          solver: (raw) => {
            const [line1, line2] = String(raw).replace(/\r/g, '').trim().split('\n');
            const arr = line1.trim().split(/\s+/);
            const target = String(line2).trim();
            const idx = arr.indexOf(target);
            return String(idx);
          },
        }),
      ],
    },
    {
      id: 'coding-logic',
      title: 'Logical Programming Challenge',
      category: 'Programming Logic',
      difficulty: 'Medium',
      questions: 5,
      duration: 30,
      marks: 15,
      description: 'Solve programming problems commonly asked in placement assessments.',
      instructions: [
        'Read each problem carefully.',
        'Select the programming language before submitting.',
        'Your code will be evaluated against test cases.',
        'Make sure your solution handles edge cases.',
        'Do not refresh the page during the test.',
      ],
      items: [
        q({
          id: 'lg-q1',
          title: 'FizzBuzz',
          description: 'Print numbers from 1 to N. For multiples of 3 print Fizz, for multiples of 5 print Buzz, and for both print FizzBuzz.',
          inputFormat: 'A single integer N.',
          outputFormat: 'N lines following the FizzBuzz rules.',
          constraints: '1 ≤ N ≤ 100',
          examples: [{ input: '5', output: '1\n2\nFizz\n4\nBuzz' }],
          python: 'n = int(input())\n# Write your solution\n',
          marks: 3,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '5', expected: '1\n2\nFizz\n4\nBuzz', sample: true },
            { id: 'h1', input: '15', expected: '1\n2\nFizz\n4\nBuzz\nFizz\n7\n8\nFizz\nBuzz\n11\nFizz\n13\n14\nFizzBuzz', sample: false },
          ],
          keywords: { Python: ['Fizz', 'Buzz', '%'], Java: ['Fizz', 'Buzz'], C: ['Fizz', 'Buzz'], 'C++': ['Fizz', 'Buzz'], JavaScript: ['Fizz', 'Buzz'] },
          solver: (raw) => {
            const n = Number(String(raw).trim());
            const lines = [];
            for (let i = 1; i <= n; i += 1) {
              if (i % 15 === 0) lines.push('FizzBuzz');
              else if (i % 3 === 0) lines.push('Fizz');
              else if (i % 5 === 0) lines.push('Buzz');
              else lines.push(String(i));
            }
            return lines.join('\n');
          },
        }),
        q({
          id: 'lg-q2',
          title: 'Two Sum',
          description: 'Find two indices whose values add up to the target. Print the 0-based indices in ascending order.',
          inputFormat: 'Line 1: space-separated integers.\nLine 2: the target integer.',
          outputFormat: 'Print two indices separated by a space.',
          constraints: '2 ≤ n ≤ 1000',
          examples: [{ input: '2 7 11 15\n9', output: '0 1' }],
          python: 'arr = list(map(int, input().split()))\ntarget = int(input())\n# Write your solution\n',
          marks: 3,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '2 7 11 15\n9', expected: '0 1', sample: true },
            { id: 'h1', input: '3 2 4\n6', expected: '1 2', sample: false },
          ],
          keywords: { Python: ['for', 'range', 'dict'], Java: ['for', 'Map'], C: ['for'], 'C++': ['unordered_map', 'for'], JavaScript: ['for', 'Map'] },
          solver: (raw) => {
            const [line1, line2] = String(raw).replace(/\r/g, '').trim().split('\n');
            const arr = line1.trim().split(/\s+/).map(Number);
            const target = Number(String(line2).trim());
            const seen = new Map();
            for (let i = 0; i < arr.length; i += 1) {
              const need = target - arr[i];
              if (seen.has(need)) return `${seen.get(need)} ${i}`;
              if (!seen.has(arr[i])) seen.set(arr[i], i);
            }
            return '';
          },
        }),
        q({
          id: 'lg-q3',
          title: 'Nth Fibonacci',
          description: 'Print the Nth Fibonacci number. F(1) = 1, F(2) = 1.',
          inputFormat: 'A single integer N.',
          outputFormat: 'Print F(N).',
          constraints: '1 ≤ N ≤ 30',
          examples: [{ input: '7', output: '13' }],
          python: 'n = int(input())\n# Write your solution\n',
          marks: 3,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '7', expected: '13', sample: true },
            { id: 'h1', input: '1', expected: '1', sample: false },
            { id: 'h2', input: '10', expected: '55', sample: false },
          ],
          keywords: { Python: ['for', 'fib', 'a, b'], Java: ['for'], C: ['for'], 'C++': ['for'], JavaScript: ['for'] },
          solver: (raw) => {
            const n = Number(String(raw).trim());
            if (n <= 2) return '1';
            let a = 1;
            let b = 1;
            for (let i = 3; i <= n; i += 1) {
              const c = a + b;
              a = b;
              b = c;
            }
            return String(b);
          },
        }),
        q({
          id: 'lg-q4',
          title: 'Anagram Check',
          description: 'Check whether two words are anagrams of each other. Ignore case.',
          inputFormat: 'Two space-separated strings A and B.',
          outputFormat: 'Print Yes if they are anagrams, otherwise print No.',
          constraints: '1 ≤ |A|, |B| ≤ 200',
          examples: [{ input: 'listen silent', output: 'Yes' }],
          python: 'a, b = input().split()\n# Write your solution\n',
          marks: 3,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: 'listen silent', expected: 'Yes', sample: true },
            { id: 'h1', input: 'hello world', expected: 'No', sample: false },
            { id: 'h2', input: 'Tea Eat', expected: 'Yes', sample: false },
          ],
          keywords: { Python: ['sorted', 'Counter'], Java: ['sort', 'toCharArray'], C: ['sort'], 'C++': ['sort'], JavaScript: ['sort', 'split'] },
          solver: (raw) => {
            const [a, b] = String(raw).trim().split(/\s+/);
            const norm = (s) => [...String(s).toLowerCase()].sort().join('');
            return norm(a) === norm(b) ? 'Yes' : 'No';
          },
        }),
        q({
          id: 'lg-q5',
          title: 'Missing Number',
          description: 'You are given n−1 distinct integers from 1 to n. Find the missing number.',
          inputFormat: 'A single line of n−1 space-separated integers.',
          outputFormat: 'Print the missing number.',
          constraints: '2 ≤ n ≤ 1000',
          examples: [{ input: '1 2 4 5', output: '3' }],
          python: 'arr = list(map(int, input().split()))\n# Write your solution\n',
          marks: 3,
          testCases: [
            { id: 's1', label: 'Sample Test Case', input: '1 2 4 5', expected: '3', sample: true },
            { id: 'h1', input: '2 3 4', expected: '1', sample: false },
            { id: 'h2', input: '1 2 3', expected: '4', sample: false },
          ],
          keywords: { Python: ['sum', 'xor', 'set'], Java: ['sum', '^'], C: ['sum', '^'], 'C++': ['accumulate', '^'], JavaScript: ['reduce', '^'] },
          solver: (raw) => {
            const arr = String(raw).trim().split(/\s+/).map(Number);
            const n = arr.length + 1;
            const expect = (n * (n + 1)) / 2;
            return String(expect - arr.reduce((a, b) => a + b, 0));
          },
        }),
      ],
    },
  ];

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function testMeta(test) {
    return {
      id: test.id,
      title: test.title,
      category: test.category,
      difficulty: test.difficulty,
      questions: test.questions,
      duration: test.duration,
      marks: test.marks,
      description: test.description,
      instructions: test.instructions.slice(),
    };
  }

  function publicQuestion(item, { includeHiddenExpected = false } = {}) {
    return {
      id: item.id,
      title: item.title,
      description: item.description,
      inputFormat: item.inputFormat,
      outputFormat: item.outputFormat,
      constraints: item.constraints,
      examples: clone(item.examples || []),
      starterCode: clone(item.starterCode),
      marks: item.marks,
      testCases: (item.testCases || []).map((tc) => {
        if (tc.sample) return clone(tc);
        const hidden = { id: tc.id, sample: false, label: tc.label || 'Hidden Test Case' };
        if (includeHiddenExpected) hidden.expected = tc.expected;
        return hidden;
      }),
    };
  }

  global.CodingData = {
    LANGUAGES,
    CATEGORIES: ['Programming', 'Python', 'Data Structures', 'Programming Logic', 'Algorithms'],
    DIFFICULTIES: ['Easy', 'Medium', 'Hard'],
    defaultStarters,
    listTests() {
      return TESTS.map(testMeta);
    },
    getTest(id) {
      return TESTS.find((t) => t.id === id) || null;
    },
    serializableTest(test) {
      if (!test) return null;
      const items = (test.items || []).map((item) => ({
        id: item.id,
        title: item.title,
        description: item.description,
        inputFormat: item.inputFormat,
        outputFormat: item.outputFormat,
        constraints: item.constraints,
        examples: clone(item.examples || []),
        starterCode: clone(item.starterCode || defaultStarters('')),
        testCases: clone(item.testCases || []),
        keywords: clone(item.keywords || {}),
        marks: item.marks || 2,
        category: test.category,
        difficulty: test.difficulty,
      }));
      const marks = items.reduce((s, q) => s + Number(q.marks || 0), 0) || test.marks || 0;
      return {
        id: test.id,
        title: test.title,
        category: test.category,
        difficulty: test.difficulty,
        questions: items.length,
        duration: test.duration || test.durationMinutes || 20,
        durationMinutes: test.duration || test.durationMinutes || 20,
        marks,
        totalMarks: marks,
        description: test.description || '',
        instructions: Array.isArray(test.instructions) ? test.instructions.slice() : [],
        status: test.status || 'published',
        contestType: test.contestType || 'none',
        contestWeekday: test.contestWeekday || 1,
        contestMonthDay: test.contestMonthDay || 1,
        items,
      };
    },
    seedManagedTests() {
      return TESTS.map((t) => this.serializableTest(t));
    },
    getPublicTest(id) {
      const test = TESTS.find((t) => t.id === id);
      if (!test) return null;
      return {
        ...testMeta(test),
        items: test.items.map((item) => publicQuestion(item)),
      };
    },
    publicQuestion,
    getQuestion(testId, questionId) {
      const test = TESTS.find((t) => t.id === testId);
      return (test?.items || []).find((item) => item.id === questionId) || null;
    },
  };
})(window);
