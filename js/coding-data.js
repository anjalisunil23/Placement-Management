/* PlaceHub — coding practice metadata (topics, starters, question shaping) */
(function (global) {
  const LANGUAGES = ['Python', 'Java', 'C', 'C++', 'JavaScript'];

  function defaultStarters(pythonBody) {
    return {
      Python: pythonBody,
      Java: 'import java.util.*;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    // Write your logic below\n  }\n}\n',
      C: '#include <stdio.h>\nint main() {\n  // Write your logic below\n  return 0;\n}\n',
      'C++': '#include <bits/stdc++.h>\nusing namespace std;\nint main() {\n  ios::sync_with_stdio(false);\n  cin.tie(nullptr);\n  // Write your logic below\n  return 0;\n}\n',
      JavaScript: "const input = require('fs').readFileSync(0, 'utf8').trim();\n// Write your logic below\n",
    };
  }

  function pythonStarterFromSampleInput(sampleIn) {
    const raw = String(sampleIn || '').replace(/\r\n/g, '\n').trim();
    if (!raw) return '# Write your logic below\n';
    const lines = raw.split('\n');
    const first = (lines[0] || '').trim();
    if (lines.length >= 2 && /^\d+$/.test(first)) {
      const n = parseInt(first, 10);
      const nums = (lines[1] || '').trim().split(/\s+/).filter(Boolean);
      if (n > 0 && nums.length === n) {
        return 'n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
      if (n > 0 && nums.length === 2 * n) {
        return 'n = int(input())\nnums = list(map(int, input().split()))\n\n# Write your logic below\n';
      }
      if (lines.length >= 3 && /^\d+$/.test((lines[1] || '').trim())) {
        const m = parseInt((lines[1] || '').trim(), 10);
        const row = (lines[2] || '').trim().split(/\s+/).filter(Boolean);
        if (m > 0 && row.length === m) {
          return 'n = int(input())\nm = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n';
        }
        return 'n = int(input())\nm = int(input())\n\n# Write your logic below\n';
      }
    }
    if (lines.length === 1) {
      const line = first;
      if (/^-?\d+$/.test(line)) return 'n = int(input())\n\n# Write your logic below\n';
      if (/^-?\d+\s+-?\d+$/.test(line)) return 'a, b = map(int, input().split())\n\n# Write your logic below\n';
      if (/^-?\d+\s+-?\d+\s+-?\d+$/.test(line)) return 'a, b, c = map(int, input().split())\n\n# Write your logic below\n';
      if (line.includes(' ')) return 'parts = input().split()\n\n# Write your logic below\n';
      return 's = input().strip()\n\n# Write your logic below\n';
    }
    return '# Write your logic below\n';
  }

  function pythonStarterFromProblem(problem) {
    const ex = (problem?.examples || [])[0];
    if (ex?.input) return pythonStarterFromSampleInput(ex.input);
    const sample = (problem?.testCases || []).find((tc) => tc.sample);
    if (sample?.input) return pythonStarterFromSampleInput(sample.input);
    const any = (problem?.testCases || []).find((tc) => tc.input);
    return pythonStarterFromSampleInput(any?.input || '');
  }

  function enrichProblemStarters(problem) {
    const starter = { ...(problem.starterCode || {}) };
    const py = String(starter.Python || '').trim();
    const needs = !py || /^#\s*Write your solution\s*$/m.test(py) || !/input\s*\(/.test(py);
    if (needs) {
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
      testCases: (item.testCases || []).map((tc) => {
        if (tc.sample) return clone(tc);
        const hidden = { id: tc.id, sample: false, label: tc.label || 'Hidden Test Case' };
        if (includeHiddenExpected) hidden.expected = tc.expected;
        return hidden;
      }),
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
    defaultStarters,
    pythonStarterFromSampleInput,
    pythonStarterFromProblem,
    enrichProblemStarters,
    publicQuestion,
  };
})(window);
