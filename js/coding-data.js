/* PlaceHub — coding practice metadata (topics, starters, question shaping) */
(function (global) {
  const LANGUAGES = ['Python', 'Java', 'C', 'C++', 'JavaScript'];

  function normalizeLanguage(language) {
    const raw = String(language || 'Python').trim().replace(/\uFF0B/g, '+').replace(/\s+/g, '');
    const lower = raw.toLowerCase();
    if (lower === 'python' || lower === 'py' || lower === 'python3') return 'Python';
    if (lower === 'javascript' || lower === 'js' || lower === 'nodejs') return 'JavaScript';
    if (lower === 'java') return 'Java';
    if (lower === 'c') return 'C';
    if (lower === 'c++' || lower === 'cpp' || lower === 'cxx' || lower === 'cplusplus') return 'C++';
    return LANGUAGES.includes(String(language || '').trim()) ? String(language).trim() : 'Python';
  }

  function defaultStarters(pythonBody) {
    return {
      Python: pythonBody,
      Java: 'import java.util.*;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    // Write your logic below\n  }\n}\n',
      C: '#include <stdio.h>\nint main() {\n  // Write your logic below\n  return 0;\n}\n',
      'C++': '#include <bits/stdc++.h>\nusing namespace std;\nint main() {\n  ios::sync_with_stdio(false);\n  cin.tie(nullptr);\n  // Write your logic below\n  return 0;\n}\n',
      JavaScript: "const input = require('fs').readFileSync(0, 'utf8').trim();\n// Write your logic below\n",
    };
  }

  function pythonFromInputFormat(format, sampleIn) {
    const f = String(format || '').toLowerCase();
    if (!f) return null;
    if (/single line string|line string|string s|a string/i.test(format)) {
      return 's = input().strip()\n\n# Write your logic below\n';
    }
    if (/brackets|line of brackets|parentheses/i.test(format)) {
      return 's = input().strip()\n\n# Write your logic below\n';
    }
    if (/two space-separated words|two words/i.test(format)) {
      return 'w1, w2 = input().split()\n\n# Write your logic below\n';
    }
    if (/single line of words|line of words|space-separated words/i.test(format)) {
      return 'words = input().split()\n\n# Write your logic below\n';
    }
    if (/three integers|3 integers|a b c/i.test(format) && !/first line|second line|\bn\b/i.test(format)) {
      return 'a, b, c = map(int, input().split())\n\n# Write your logic below\n';
    }
    if (/two integers|2 integers|\ba and b\b/i.test(format) && !/first line|three|n integers/i.test(format)) {
      return 'a, b = map(int, input().split())\n\n# Write your logic below\n';
    }
    if (/one integer|single integer|one non-negative integer/i.test(format) && !/first line|second line|then/i.test(format)) {
      return 'n = int(input())\n\n# Write your logic below\n';
    }
    if (/then n lines|n lines of n|matrix|n×n|n x n/i.test(format)) {
      return 'n = int(input())\nmatrix = [list(map(int, input().split())) for _ in range(n)]\n\n# Write your logic below\n';
    }
    if (/line 1:\s*n\s*m|n m\b/i.test(format) && /line 2|line 3|second line|third line/i.test(format)) {
      return 'n, m = map(int, input().split())\narr1 = list(map(int, input().split()))\narr2 = list(map(int, input().split()))\n\n# Write your logic below\n';
    }
    if (/first line n\. second line n-1|n-1 integers/i.test(format)) {
      return 'n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
    }
    if (/first line n x|first line n t|n x\b|n t\b/i.test(format)) {
      return 'n, x = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
    }
    if (/first line n k|n k\b/i.test(format)) {
      return 'n, k = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
    }
    if (/first line n\. second line/i.test(format)) {
      return 'n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
    }
    return null;
  }

  function pythonStarterFromSampleInput(sampleIn, inputFormat = '') {
    const raw = String(sampleIn || '').replace(/\r\n/g, '\n').trim();
    const fmt = String(inputFormat || '').toLowerCase();
    if (!raw) return '# Write your logic below\n';
    const lines = raw.split('\n');
    const first = (lines[0] || '').trim();

    if (lines.length >= 3 && /^\d+\s+\d+$/.test(first)) {
      const second = (lines[1] || '').trim();
      const third = (lines[2] || '').trim();
      if (/^-?\d+(\s+-?\d+)*$/.test(second) && /^-?\d+(\s+-?\d+)*$/.test(third)) {
        return 'n, m = map(int, input().split())\narr1 = list(map(int, input().split()))\narr2 = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
    }
    if (lines.length >= 2 && /^\d+\s+-?\d+$/.test(first)) {
      const second = (lines[1] || '').trim();
      if (/^-?\d+(\s+-?\d+)+$/.test(second)) {
        const label = fmt.includes(' k') ? 'k' : (fmt.includes(' x') ? 'x' : 't');
        return `n, ${label} = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n`;
      }
    }
    if (lines.length >= 2 && /^\d+$/.test(first)) {
      const n = parseInt(first, 10);
      const second = (lines[1] || '').trim();
      const nums = second.split(/\s+/).filter(Boolean);
      if (fmt.includes('2n') && n > 0 && nums.length === 2 * n) {
        return 'n = int(input())\nnums = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
      if (n > 0 && nums.length === 2 * n) {
        return 'n = int(input())\nnums = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
      if (n > 0 && nums.length === n) {
        return 'n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
      if (n > 0 && nums.length === Math.max(1, n - 1)) {
        return 'n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
      if (lines.length >= 3 && /^\d+$/.test((lines[1] || '').trim())) {
        return 'n, m = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
      if (lines.length > 2 && /^-?\d+(\s+-?\d+)+$/.test(second)) {
        return 'n = int(input())\nmatrix = [list(map(int, input().split())) for _ in range(n)]\n\n# Write your logic below\n';
      }
      if (lines.length === 2) {
        return 'n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
    }
    if (lines.length === 1) {
      const line = first;
      if (/^-?\d+$/.test(line)) return 'n = int(input())\n\n# Write your logic below\n';
      if (/^-?\d+\s+-?\d+$/.test(line)) return 'a, b = map(int, input().split())\n\n# Write your logic below\n';
      if (/^-?\d+\s+-?\d+\s+-?\d+$/.test(line)) return 'a, b, c = map(int, input().split())\n\n# Write your logic below\n';
      if (/^[()[\]{}]+$/.test(line)) return 's = input().strip()\n\n# Write your logic below\n';
      if (/^[A-Za-z]+(\s+[A-Za-z]+)+$/.test(line) && !/\d/.test(line)) {
        const parts = line.split(/\s+/).filter(Boolean);
        if (parts.length === 2) return 'w1, w2 = input().split()\n\n# Write your logic below\n';
        return 'words = input().split()\n\n# Write your logic below\n';
      }
      if (line.includes(' ')) {
        if (/^-?\d/.test(line)) return 'values = list(map(int, input().split()))\n\n# Write your logic below\n';
        return 'parts = input().split()\n\n# Write your logic below\n';
      }
      return 's = input().strip()\n\n# Write your logic below\n';
    }
    if (lines.length >= 3 && /^\d+$/.test(first)) {
      return 'n = int(input())\nmatrix = [list(map(int, input().split())) for _ in range(n)]\n\n# Write your logic below\n';
    }
    return '# Write your logic below\n';
  }

  function onlyInputBoilerplate(py) {
    const lines = String(py || '').split('\n');
    for (const line of lines) {
      const t = line.trim();
      if (!t || t.startsWith('#')) continue;
      if (/^(?:[a-z_,\s]+\s*=\s*)?(?:int\(input\(\)\)|input\(\)(?:\.strip\(\))?|map\(int,\s*input\(\)\.split\(\)\)|list\(map\(int,\s*input\(\)\.split\(\)\)\)|\[list\(map\(int,\s*input\(\)\.split\(\)\)\)\s+for\s+_\s+in\s+range\([a-z_]\w*\)\])$/i.test(t)) continue;
      if (/^[a-z_]\w*\s*=\s*input\(\)\.split\(\)$/i.test(t)) continue;
      return false;
    }
    return true;
  }

  function formatStarterFitsSample(starter, sampleIn) {
    const raw = String(sampleIn || '').replace(/\r\n/g, '\n').trim();
    if (!raw || !starter) return true;
    const first = (raw.split('\n')[0] || '').trim();
    if (/^n,\s*(?:x|t|k)\s*=\s*map/m.test(starter)) {
      return /^\d+\s+-?\d+$/.test(first);
    }
    if (/^n,\s*x,\s*_\s*=\s*map/m.test(starter)) {
      return /^\d+\s+-?\d+\s+-?\d+/.test(first);
    }
    return true;
  }

  function resolveSampleInput(problem) {
    const ex = (problem?.examples || []).find((e) => String(e?.input || '').trim());
    if (ex?.input) return ex.input;
    const sample = (problem?.testCases || []).find((tc) => tc.sample && String(tc.input || '').trim());
    if (sample?.input) return sample.input;
    const any = (problem?.testCases || []).find((tc) => String(tc.input || '').trim());
    return any?.input || '';
  }

  function effectiveRunStdin(problem, customStdin) {
    const custom = String(customStdin ?? '');
    if (custom.trim()) return custom;
    return resolveSampleInput(problem) || custom;
  }

  function pythonStarterFromProblem(problem) {
    const sampleIn = resolveSampleInput(problem);
    const fromFormat = pythonFromInputFormat(problem?.inputFormat, sampleIn);
    if (fromFormat && formatStarterFitsSample(fromFormat, sampleIn)) return fromFormat;
    const ex = (problem?.examples || [])[0];
    if (ex?.input) return pythonStarterFromSampleInput(ex.input, problem?.inputFormat);
    const sample = (problem?.testCases || []).find((tc) => tc.sample);
    if (sample?.input) return pythonStarterFromSampleInput(sample.input, problem?.inputFormat);
    const any = (problem?.testCases || []).find((tc) => tc.input);
    return pythonStarterFromSampleInput(any?.input || '', problem?.inputFormat);
  }

  function enrichProblemStarters(problem) {
    if (problem?.starterCodeLocked) return { ...problem };
    const starter = { ...(problem.starterCode || {}) };
    const py = String(starter.Python || '').trim();
    const replace = !py || /^#\s*Write your solution\s*$/m.test(py) || onlyInputBoilerplate(py);
    if (replace) {
      const python = pythonStarterFromProblem(problem);
      Object.assign(starter, defaultStarters(python));
    }
    return { ...problem, starterCode: starter };
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
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
      difficulty: item.difficulty,
      category: item.category,
      testCases: (() => {
        const cases = (item.testCases || []).map((tc) => {
        if (tc.sample) return clone(tc);
        const hidden = { id: tc.id, sample: false, label: tc.label || 'Hidden Test Case' };
        if (includeHiddenExpected) hidden.expected = tc.expected;
        return hidden;
        });
        const sampleHasInput = cases.some((tc) => tc.sample && String(tc.input || '').trim());
        if (!sampleHasInput) {
          const ex = (item.examples || []).find((e) => String(e?.input || '').trim());
          if (ex) {
            cases.unshift({
              id: 'example-sample',
              sample: true,
              label: 'Sample Test Case',
              input: ex.input,
              expected: ex.output || '',
            });
          }
        }
        return cases;
      })(),
    };
  }

  const CODING_TOPICS = ['Algorithms', 'Database', 'Shell', 'Concurrency', 'JavaScript', 'pandas'];
  const TOPIC_HIERARCHY = {
    Algorithms: {
      Arrays: ['Array', 'Two Pointers', 'Prefix Sum', 'Sliding Window', 'Enumeration', 'Matrix', 'Simulation'],
      Searching: ['Binary Search'],
      Sorting: ['Sorting', 'Merge Sort', 'Counting Sort', 'Bucket Sort', 'Radix Sort', 'Quickselect'],
      Hashing: ['Hash Table', 'Hash Function'],
      'Stack & Queue': ['Stack', 'Monotonic Stack', 'Queue', 'Monotonic Queue'],
      'Linked List': ['Linked List', 'Doubly Linked List'],
      Trees: ['Tree', 'Binary Tree', 'Binary Search Tree', 'Trie', 'Segment Tree', 'Binary Indexed Tree'],
      Graphs: [
        'Graph Theory', 'Depth-First Search', 'Breadth-First Search', 'Shortest Path', 'Topological Sort',
        'Minimum Spanning Tree', 'Strongly Connected Component', 'Biconnected Component', 'Union-Find',
      ],
      'Recursion & Backtracking': ['Recursion', 'Backtracking', 'Divide and Conquer', 'Memoization'],
      Optimization: ['Dynamic Programming', 'Greedy'],
      Mathematics: [
        'Math', 'Number Theory', 'Combinatorics', 'Geometry', 'Probability and Statistics', 'Game Theory', 'Minimax',
      ],
      'Bit Operations': ['Bit Manipulation', 'Bitmask'],
      'Advanced Techniques': [
        'Rolling Hash', 'Sweep Line', 'Meet in the Middle', 'Randomized', 'Reservoir Sampling', 'Interactive',
      ],
    },
    Database: {
      Database: [
        'SQL Basics', 'SQL Operations', 'Joins', 'Aggregate Functions', 'Subqueries', 'CTE', 'Window Functions',
        'Views', 'Transactions', 'Indexing', 'Normalization', 'Database Design',
      ],
    },
    Shell: {
      Shell: [
        'Basic Commands', 'File Operations', 'Text Processing', 'Shell Programming', 'Pipes and Redirection',
        'Environment Variables', 'Processes', 'Shell Scripts',
      ],
    },
    Concurrency: {
      Concurrency: [
        'Process', 'Thread', 'Multithreading', 'Parallelism', 'Synchronization', 'Mutex', 'Semaphore',
        'Race Condition', 'Deadlock', 'Thread Pool', 'Producer-Consumer', 'Reader-Writer', 'Atomic Operations',
      ],
    },
    JavaScript: {
      JavaScript: [
        'Basics', 'Arrays', 'Strings', 'Objects', 'Modern JavaScript', 'Asynchronous JavaScript', 'DOM and Web',
        'Closures', 'Scope', 'Hoisting', 'Promises', 'async/await', 'Fetch API', 'Classes', 'Error Handling',
      ],
    },
    pandas: {
      pandas: [
        'Series', 'DataFrame', 'Reading Data', 'Data Selection', 'Data Cleaning', 'Data Manipulation', 'Grouping',
        'Merge / Join / Concat', 'Data Analysis', 'Time Series', 'String Operations', 'Exporting Data',
      ],
    },
  };
  const LEGACY_TOPIC_MAP = {
    Programming: 'Algorithms',
    Python: 'Shell',
    'Data Structures': 'Algorithms',
    'Programming Logic': 'Algorithms',
  };

  global.CodingData = {
    LANGUAGES,
    CATEGORIES: CODING_TOPICS,
    TOPIC_HIERARCHY,
    TOPIC_FILTERS: [
      { value: '', label: 'All Topics', icon: 'bi-collection', tone: 'all' },
      { value: 'Algorithms', label: 'Algorithms', icon: 'bi-diagram-3', tone: 'algorithms' },
      { value: 'Database', label: 'Database', icon: 'bi-database', tone: 'database' },
      { value: 'Shell', label: 'Shell', icon: 'bi-terminal', tone: 'shell' },
      { value: 'Concurrency', label: 'Concurrency', icon: 'bi-shuffle', tone: 'concurrency' },
      { value: 'JavaScript', label: 'JavaScript', icon: 'bi-filetype-js', tone: 'javascript' },
      { value: 'pandas', label: 'pandas', icon: 'bi-bar-chart-line', tone: 'pandas' },
    ],
    normalizeTopic(category) {
      const c = String(category || '').trim();
      return LEGACY_TOPIC_MAP[c] || c || 'Algorithms';
    },
    DIFFICULTIES: ['Easy', 'Medium', 'Hard'],
    normalizeLanguage,
    defaultStarters,
    pythonStarterFromSampleInput,
    pythonStarterFromProblem,
    enrichProblemStarters,
    effectiveRunStdin,
    resolveSampleInput,
    onlyInputBoilerplate,
    publicQuestion,
  };
})(window);
