<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * KTU 2019 B.Tech course codes and module syllabi for staff question generation.
 */
final class CourseSyllabusCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return self::courses();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $code): ?array
    {
        $want = strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
        if ($want === '') {
            return null;
        }
        foreach (self::courses() as $course) {
            if ($course['code'] === $want) {
                return $course;
            }
        }

        return null;
    }

    /**
     * Courses for one staff department, plus shared college courses.
     * Other departments are omitted.
     *
     * @return list<array<string, mixed>>
     */
    public static function forStaff(string $code, string $name, string $shortName = ''): array
    {
        $key = self::matchKey($code, $name, $shortName);

        return array_values(array_filter(
            self::all(),
            static function (array $course) use ($key): bool {
                $dept = strtoupper((string) ($course['department'] ?? ''));
                if ($dept === 'COMMON') {
                    return true;
                }

                return $key !== '' && $dept === $key;
            }
        ));
    }

    /**
     * @param array<string, mixed> $course
     */
    public static function visibleToStaff(array $course, string $code, string $name, string $shortName = ''): bool
    {
        $dept = strtoupper((string) ($course['department'] ?? ''));
        if ($dept === 'COMMON') {
            return true;
        }
        $key = self::matchKey($code, $name, $shortName);

        return $key !== '' && $dept === $key;
    }

    private static function matchKey(string $code, string $name, string $shortName): string
    {
        $tokens = [];
        foreach ([$code, $shortName, $name] as $part) {
            $norm = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $part));
            foreach (preg_split('/\s+/', trim($norm)) ?: [] as $word) {
                if ($word !== '') {
                    $tokens[] = $word;
                }
            }
        }
        $exact = [
            'CSE' => 'CSE', 'CS' => 'CSE', 'CST' => 'CSE', 'CSAI' => 'CSE',
            'ECE' => 'ECE', 'EC' => 'ECE', 'ECT' => 'ECE',
            'EEE' => 'EEE', 'EE' => 'EEE', 'EET' => 'EEE',
            'ME' => 'ME', 'MECH' => 'ME', 'MET' => 'ME',
            'CE' => 'CE', 'CIVIL' => 'CE', 'CET' => 'CE',
            'MCA' => 'MCA', 'BCA' => 'BCA', 'INMCA' => 'MCA',
            'IT' => 'IT', 'AD' => 'AD', 'AIDS' => 'AD',
            'CH' => 'CH', 'CHEM' => 'CH',
        ];
        foreach ($tokens as $token) {
            if (isset($exact[$token])) {
                return $exact[$token];
            }
        }
        $joined = implode(' ', $tokens);
        if (str_contains($joined, 'COMPUTER APPLICATION')) {
            return 'MCA';
        }
        if (str_contains($joined, 'COMPUTER SCIENCE')) {
            return 'CSE';
        }
        if (str_contains($joined, 'INFORMATION TECHNOLOGY')) {
            return 'IT';
        }
        if (str_contains($joined, 'COMMUNICATION')) {
            return 'ECE';
        }
        if (str_contains($joined, 'ELECTRICAL')) {
            return 'EEE';
        }
        if (str_contains($joined, 'MECHANICAL')) {
            return 'ME';
        }
        if (str_contains($joined, 'CIVIL')) {
            return 'CE';
        }

        return '';
    }

    /**
     * @param mixed $payload AES searchSyllabus4Placement body
     * @return list<array{code:string,title:string,semsubId:string,subjectType:string,department:string}>
     */
    public static function filterSearchRows(mixed $payload, string $code, string $name, string $shortName): array
    {
        $out = [];
        $seen = [];
        foreach (self::extractSearchList($payload) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $subjectCode = strtoupper(trim((string) ($row['subjectCode'] ?? $row['code'] ?? '')));
            $subjectName = trim((string) ($row['subjectName'] ?? $row['title'] ?? ''));
            $semsubId = trim((string) ($row['semsubId'] ?? $row['semSubId'] ?? $row['sem_sub_id'] ?? ''));
            if ($subjectCode === '') {
                continue;
            }
            $key = $subjectCode . '|' . $semsubId;
            if (isset($seen[$key])) {
                continue;
            }
            if (!self::subjectVisibleToStaff($subjectCode, $code, $name, $shortName)) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'code' => $subjectCode,
                'title' => $subjectName,
                'semsubId' => $semsubId,
                'subjectType' => trim((string) ($row['subjectType'] ?? '')),
                'department' => self::subjectDepartment($subjectCode),
            ];
        }

        return $out;
    }

    public static function subjectVisibleToStaff(string $subjectCode, string $code, string $name, string $shortName): bool
    {
        $owner = self::subjectDepartment($subjectCode);
        if ($owner === '') {
            return true;
        }
        $key = self::matchKey($code, $name, $shortName);

        return $key !== '' && $owner === $key;
    }

    public static function subjectDepartment(string $subjectCode): string
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $subjectCode));
        if ($compact === '') {
            return '';
        }
        $tokens = [
            'INMCA' => 'MCA', 'INTMCA' => 'MCA', 'CSAI' => 'CSE', 'AIDS' => 'AD',
            'MCA' => 'MCA', 'BCA' => 'BCA', 'CSE' => 'CSE', 'CST' => 'CSE',
            'ECE' => 'ECE', 'ECT' => 'ECE', 'EEE' => 'EEE', 'EET' => 'EEE',
            'MECH' => 'ME', 'MET' => 'ME', 'CIVIL' => 'CE', 'CET' => 'CE', 'CHEM' => 'CH',
            'CS' => 'CSE', 'CT' => 'CSE', 'CY' => 'CSE',
            'AD' => 'AD', 'IT' => 'IT', 'EC' => 'ECE', 'EE' => 'EEE',
            'ME' => 'ME', 'CE' => 'CE', 'CH' => 'CH',
        ];
        uksort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($tokens as $token => $key) {
            if (str_contains($compact, $token)) {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param mixed $payload
     * @return list<mixed>
     */
    private static function extractSearchList(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach (['data', 'subjects', 'syllabus', 'rows', 'result'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return self::extractSearchList($payload[$key]);
            }
        }

        return isset($payload['subjectCode']) || isset($payload['code']) ? [$payload] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function courses(): array
    {
        $rows = [
            ['Common', 'MAT101', 'Linear Algebra and Calculus', [
                'Systems of linear equations, matrices, rank, and inverse.',
                'Eigenvalues, eigenvectors, and diagonalization.',
                'Limits, continuity, and differentiation of one-variable functions.',
                'Partial derivatives and maxima and minima of functions of several variables.',
                'Definite integrals and applications of integration.',
            ]],
            ['Common', 'MAT102', 'Vector Calculus, Differential Equations and Transforms', [
                'Vector differentiation, gradient, divergence, and curl.',
                'Line, surface, and volume integrals and vector integral theorems.',
                'First-order and higher-order ordinary differential equations.',
                'Partial differential equations and applications.',
                'Laplace transforms and Fourier series.',
            ]],
            ['Common', 'EST100', 'Engineering Mechanics', [
                'Forces, moments, and equilibrium of particles and rigid bodies.',
                'Friction, centroid, and moment of inertia.',
                'Kinematics of particles and rigid bodies.',
                'Kinetics, work, and energy.',
                'Impulse, momentum, and vibration of simple systems.',
            ]],
            ['Common', 'EST102', 'Programming in C', [
                'Problem solving, algorithms, and the C programming environment.',
                'Data types, operators, expressions, and control statements.',
                'Arrays, strings, and functions.',
                'Pointers, structures, and unions.',
                'Files and an introduction to dynamic memory.',
            ]],
            ['Common', 'EST110', 'Engineering Graphics', [
                'Orthographic projection of points, lines, and planes.',
                'Projection of solids.',
                'Sections of solids and development of surfaces.',
                'Isometric projection.',
                'Introduction to CAD drafting.',
            ]],
            ['Common', 'EST120', 'Basics of Civil and Mechanical Engineering', [
                'Materials, surveying, and building components.',
                'Water supply, wastewater, and transportation basics.',
                'Thermodynamics and internal combustion engines.',
                'Power plants, refrigeration, and manufacturing processes.',
                'Introduction to mechanical power transmission.',
            ]],
            ['Common', 'EST130', 'Basics of Electrical and Electronics Engineering', [
                'DC and AC circuit fundamentals.',
                'Transformers and electrical machines.',
                'Semiconductor diodes and rectifiers.',
                'Transistors, amplifiers, and digital basics.',
                'Measuring instruments and electrical safety.',
            ]],
            ['Common', 'EST200', 'Design and Engineering', [
                'Design thinking and problem identification.',
                'Customer needs, specifications, and concept generation.',
                'Design analysis, prototyping, and testing.',
                'Design for manufacture, sustainability, and cost.',
                'Communication of a design solution.',
            ]],
            ['Common', 'HUT200', 'Professional Ethics', [
                'Human values and professional ethics.',
                'Engineering as social experimentation.',
                'Safety, risk, and responsibility.',
                'Rights, duties, and workplace ethics.',
                'Global issues, environment, and ethical codes.',
            ]],
            ['Common', 'HUT300', 'Industrial Economics and Foreign Trade', [
                'Demand, supply, and market structures.',
                'Production, cost, and pricing decisions.',
                'National income and industrial growth.',
                'Money, banking, and inflation.',
                'International trade, balance of payments, and trade policy.',
            ]],
            ['Common', 'MCN201', 'Sustainable Engineering', [
                'Sustainability concepts and the UN development goals.',
                'Environmental impact and life-cycle thinking.',
                'Energy, water, and material resources.',
                'Sustainable design and cleaner production.',
                'Environmental regulations and case studies.',
            ]],
            ['CSE', 'MAT203', 'Discrete Mathematical Structures', [
                'Logic, proofs, sets, and functions.',
                'Relations, partial orders, and lattices.',
                'Counting, permutations, combinations, and recurrence.',
                'Graphs, paths, trees, and connectivity.',
                'Algebraic structures: groups, rings, and Boolean algebra.',
            ]],
            ['CSE', 'CST201', 'Data Structures', [
                'Abstract data types, arrays, searching, and sorting.',
                'Stacks, queues, and their applications.',
                'Linked lists and memory management.',
                'Trees, binary search trees, and heaps.',
                'Graphs, traversal, shortest paths, and hashing.',
            ]],
            ['CSE', 'CST203', 'Logic System Design', [
                'Number systems and Boolean algebra.',
                'Combinational logic, adders, and multiplexers.',
                'Sequential circuits, flip-flops, and registers.',
                'Counters, state machines, and timing.',
                'Memory, programmable logic, and an introduction to HDL.',
            ]],
            ['CSE', 'CST205', 'Object Oriented Programming Using Java', [
                'Objects, classes, and the Java platform.',
                'Inheritance, polymorphism, and interfaces.',
                'Exception handling and packages.',
                'Collections, generics, and file I/O.',
                'GUI basics and event-driven programming.',
            ]],
            ['CSE', 'MAT206', 'Graph Theory', [
                'Graphs, degree, paths, and connectivity.',
                'Trees, spanning trees, and traversals.',
                'Eulerian and Hamiltonian graphs.',
                'Planarity, colouring, and matching.',
                'Shortest paths, network flows, and applications.',
            ]],
            ['CSE', 'CST202', 'Computer Organization and Architecture', [
                'Functional units, instruction sets, and addressing.',
                'Arithmetic and logic unit and number representation.',
                'Processor datapath and control.',
                'Memory hierarchy, cache, and virtual memory.',
                'I/O organization and pipelining.',
            ]],
            ['CSE', 'CST204', 'Database Management Systems', [
                'Data models, schemas, and the entity-relationship model.',
                'Relational model, integrity constraints, and SQL DDL.',
                'SQL queries, joins, nested queries, and indexing.',
                'Normalization and functional dependencies.',
                'Transactions, concurrency, recovery, and an introduction to NoSQL.',
            ]],
            ['CSE', 'CST206', 'Operating Systems', [
                'Processes, threads, and CPU scheduling.',
                'Process synchronization and deadlocks.',
                'Memory management and virtual memory.',
                'File systems and storage.',
                'I/O, protection, and an introduction to distributed systems.',
            ]],
            ['CSE', 'CST301', 'Formal Languages and Automata Theory', [
                'Finite automata and regular languages.',
                'Regular expressions and properties of regular languages.',
                'Context-free grammars and pushdown automata.',
                'Normal forms and parsing.',
                'Turing machines and undecidability.',
            ]],
            ['CSE', 'CST303', 'Computer Networks', [
                'Network models, physical layer, and transmission.',
                'Data link control, MAC, and local area networks.',
                'Network layer, IP addressing, and routing.',
                'Transport layer, TCP, and UDP.',
                'Application protocols and network security basics.',
            ]],
            ['CSE', 'CST305', 'System Software', [
                'Assemblers and assembly language processing.',
                'Loaders and linkers.',
                'Macro processors.',
                'Compilers: lexical analysis and parsing overview.',
                'Text editors, debuggers, and an introduction to system software tools.',
            ]],
            ['CSE', 'CST307', 'Microprocessors and Microcontrollers', [
                '8086 architecture, addressing, and instruction set.',
                'Assembly programming and interrupts.',
                'Interfacing memory and peripherals.',
                '8051 microcontroller architecture and programming.',
                'Embedded interfacing applications.',
            ]],
            ['CSE', 'CST309', 'Management of Software Systems', [
                'Software process models and requirements.',
                'Project planning, estimation, and scheduling.',
                'Design, coding standards, and configuration management.',
                'Testing, quality, and maintenance.',
                'Risk, people, and software project management.',
            ]],
            ['CSE', 'CST302', 'Compiler Design', [
                'Compiler phases and lexical analysis.',
                'Syntax analysis and parsing.',
                'Syntax-directed translation and intermediate code.',
                'Runtime environments and symbol tables.',
                'Code generation and optimization.',
            ]],
            ['CSE', 'CST304', 'Computer Graphics and Image Processing', [
                'Graphics systems, output primitives, and attributes.',
                '2D and 3D transformations and viewing.',
                'Visible surface detection and illumination.',
                'Digital image fundamentals and enhancement.',
                'Segmentation and an introduction to image compression.',
            ]],
            ['CSE', 'CST306', 'Algorithm Analysis and Design', [
                'Asymptotic analysis and recurrence relations.',
                'Divide and conquer and greedy methods.',
                'Dynamic programming.',
                'Backtracking and branch and bound.',
                'Complexity classes, NP-completeness, and approximation.',
            ]],
            ['CSE', 'CST401', 'Artificial Intelligence', [
                'Intelligent agents and problem solving by search.',
                'Adversarial search and constraint satisfaction.',
                'Knowledge representation and reasoning.',
                'Planning and uncertainty.',
                'Machine learning basics and applications.',
            ]],
            ['CSE', 'CST402', 'Distributed Computing', [
                'Distributed system models and communication.',
                'Clocks, ordering, and global state.',
                'Coordination, mutual exclusion, and election.',
                'Consistency, replication, and fault tolerance.',
                'Distributed file systems and an introduction to cloud systems.',
            ]],
            ['ECE', 'ECT201', 'Solid State Devices', [
                'Semiconductor physics and carrier transport.',
                'PN junctions and diode characteristics.',
                'Bipolar junction transistors.',
                'MOS capacitors and MOSFETs.',
                'Optoelectronic and power semiconductor devices.',
            ]],
            ['ECE', 'ECT203', 'Logic Circuit Design', [
                'Number systems and Boolean algebra.',
                'Combinational circuits and minimization.',
                'Sequential circuits and flip-flops.',
                'Registers, counters, and state machines.',
                'Memory and programmable logic devices.',
            ]],
            ['ECE', 'ECT205', 'Network Theory', [
                'Circuit elements, Kirchhoff laws, and network theorems.',
                'Transient response of RL, RC, and RLC circuits.',
                'Sinusoidal steady state and phasors.',
                'Coupled circuits, resonance, and two-port networks.',
                'Network functions and filters.',
            ]],
            ['ECE', 'ECT202', 'Analog Circuits', [
                'Diode circuits and rectifiers.',
                'BJT and MOSFET biasing.',
                'Small-signal amplifiers.',
                'Feedback amplifiers and oscillators.',
                'Power amplifiers and an introduction to operational amplifiers.',
            ]],
            ['ECE', 'ECT204', 'Signals and Systems', [
                'Continuous and discrete-time signals and systems.',
                'Linear time-invariant systems and convolution.',
                'Fourier series and Fourier transform.',
                'Laplace transform and continuous-time system analysis.',
                'Z-transform and sampling.',
            ]],
            ['EEE', 'EET201', 'Circuits and Networks', [
                'DC circuit analysis and network theorems.',
                'AC steady-state analysis and power.',
                'Transient analysis of first- and second-order circuits.',
                'Coupled circuits and resonance.',
                'Two-port networks and network topology.',
            ]],
            ['EEE', 'EET203', 'Measurements and Instrumentation', [
                'Measurement errors and instrument characteristics.',
                'Electromechanical indicating instruments.',
                'Bridges and potentiometers.',
                'Transducers and signal conditioning.',
                'Digital instruments and data acquisition.',
            ]],
            ['ME', 'MET201', 'Mechanics of Solids', [
                'Stress, strain, and elastic constants.',
                'Axial loading and thermal stress.',
                'Shear force, bending moment, and bending stress.',
                'Torsion of shafts and deflection of beams.',
                'Columns and combined stresses.',
            ]],
            ['ME', 'MET203', 'Mechanics of Fluids', [
                'Fluid properties and fluid statics.',
                'Kinematics and dynamics of fluid flow.',
                'Bernoulli equation and flow measurement.',
                'Viscous flow in pipes and losses.',
                'Dimensional analysis and an introduction to boundary layers.',
            ]],
            ['CE', 'CET201', 'Mechanics of Solids', [
                'Stress, strain, and material behaviour.',
                'Axially loaded members.',
                'Bending, shear, and torsion.',
                'Deflection of beams.',
                'Columns and principal stresses.',
            ]],
            ['CE', 'CET203', 'Fluid Mechanics and Hydraulics', [
                'Fluid properties and hydrostatics.',
                'Fluid kinematics and the Bernoulli equation.',
                'Pipe flow and losses.',
                'Open-channel flow.',
                'Hydraulic machines: turbines and pumps.',
            ]],
            ['CE', 'CET205', 'Surveying and Geomatics', [
                'Chain, compass, and plane-table surveying.',
                'Levelling and contouring.',
                'Theodolite traversing.',
                'Curves and setting out.',
                'Total station, GPS, and an introduction to remote sensing.',
            ]],
        ];

        $courses = [];
        foreach ($rows as [$department, $code, $title, $topics]) {
            $modules = [];
            foreach ($topics as $index => $topic) {
                $modules[] = [
                    'name' => 'Module ' . ($index + 1),
                    'topics' => $topic,
                ];
            }
            $courses[] = [
                'code' => $code,
                'title' => $title,
                'department' => $department,
                'scheme' => 'KTU 2019',
                'modules' => $modules,
            ];
        }

        return $courses;
    }
}
