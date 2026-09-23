<?php

declare(strict_types=1);

/**
 * Generates the PHP 7.4-compatible build of this package.
 * ===========================================================================
 * THE OUTPUT IS GENERATED. Never hand-edit this repository — edit the 8.1 source and re-run
 * this. The whole point of a transform is that the 7.4 build is not a fork.
 *
 * WHY A PARSER AND NOT REGEX. The riskiest transform here is named arguments:
 * turning `new Client(baseUrl: $b, apiKey: $k)` into positional form requires
 * knowing the declaration's parameter order. A regex that gets that wrong does
 * not fail — it silently swaps two arguments. This repository has already
 * shipped one lint-clean, runtime-broken release from a regex that matched the
 * wrong thing, and that is not a lesson worth learning twice.
 *
 * WHAT IS TRANSFORMED
 *   readonly properties and params     8.1  -> dropped
 *   promoted constructor params        8.0  -> explicit property + assignment
 *   named arguments                    8.0  -> positional, resolved from the AST
 *   union types (string|int)           8.0  -> type removed, kept in the docblock
 *   new in initialiser                 8.1  -> null default, constructed in body
 *   non-capturing catch                8.0  -> catch (T $e)
 *   trailing comma in declarations     8.0  -> removed by reprinting
 *   str_contains / str_starts_with     8.0  -> polyfilled
 *
 * WHAT IS LOST, deliberately and documented: readonly. The 7.4 build permits
 * mutation that the 8.1 build refuses at compile time. Nothing in the package
 * relies on callers respecting it, but the two builds are not identical and
 * that should be known rather than discovered.
 *
 * Usage:  php tools/build-php74.php
 */

require __DIR__ . '/../.tools/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter;

/*
 * INPUT is the 8.1 package, which lives in its own repository. OUTPUT is this
 * repository, written in place — so `git diff` after a build is exactly the
 * change, and nothing reaches a client-facing repo without a person seeing it.
 *
 * The two are meant to be checked out side by side:
 *
 *   GitHub/Trace-it-Composer-Package    the 8.1 source
 *   GitHub/Trace-it-qr-php74            this repository
 *
 * Override with an argument or TRACEIT_SRC if your layout differs.
 */
$out  = dirname(__DIR__);
$root = rtrim($argv[1] ?? getenv("TRACEIT_SRC") ?: $out . "/../Trace-it-Composer-Package", "/\\\\");

if (!is_dir($root . "/src")) {
    fwrite(STDERR, "Cannot find the 8.1 source at $root\n"
        . "Clone VeriteIT/Trace-it-Composer-Package beside this repository, or pass its path:\n"
        . "  php tools/build-php74.php /path/to/Trace-it-Composer-Package\n");
    exit(1);
}
echo "source: $root\noutput: $out\n\n";

// ── collect sources ──────────────────────────────────────────────────────
// examples/ and snippets/ ship to the client too, so they are transformed on the
// same terms. preflight.php in particular uses match().
$files = [];
foreach (['src', 'examples', 'snippets'] as $dir) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);

$parser  = (new ParserFactory())->createForNewestSupportedVersion();
$printer = new PrettyPrinter\Standard();

// Reads the OUTPUT back, pinned to 7.4. See the check pass at the end of the file.
$parser74 = (new ParserFactory())->createForVersion(PhpVersion::fromString('7.4'));

/*
 * PASS 1 — build the signature map that makes named-argument resolution safe.
 * Keyed "Class::method" and "Class::__construct", value is the ordered list of
 * parameter names.
 */
$sigs = [];
$asts = [];
foreach ($files as $file) {
    $code = file_get_contents($file);
    $stmts = $parser->parse($code);
    $asts[$file] = [$code, $stmts];

    $ns = '';
    $collect = function (array $nodes, string $class = '') use (&$collect, &$sigs, &$ns) {
        foreach ($nodes as $n) {
            if ($n instanceof Node\Stmt\Namespace_) {
                $ns = $n->name ? $n->name->toString() : '';
                $collect($n->stmts);
            } elseif ($n instanceof Node\Stmt\Class_ || $n instanceof Node\Stmt\Interface_) {
                $collect($n->stmts, $n->name ? $n->name->toString() : '');
            } elseif ($n instanceof Node\Stmt\ClassMethod && $class !== '') {
                $params = [];
                foreach ($n->params as $p) {
                    $params[] = [
                        'name'    => $p->var instanceof Node\Expr\Variable ? (string) $p->var->name : '',
                        'default' => $p->default,   // needed to fill gaps positionally
                    ];
                }
                $sigs[$class . '::' . $n->name->toString()] = $params;
            }
        }
    };
    $collect($stmts);
}

// ── the transform ────────────────────────────────────────────────────────
final class Php74Visitor extends NodeVisitorAbstract
{
    public array $notes = [];

    public function __construct(private array $sigs) {}

    public function enterNode(Node $node)
    {
        // union types -> no type (the docblock already documents them)
        foreach (['type', 'returnType'] as $slot) {
            if (isset($node->$slot) && $node->$slot instanceof Node\UnionType) {
                $node->$slot = null;
                $this->notes[] = 'union type removed';
            }
        }

        /*
         * `mixed` (8.0) and `never` (8.1) -> no type.
         *
         * This one is nastier than it looks, and it shipped once. On 7.4 `mixed` is
         * not a reserved word, so `: mixed` is not a syntax error — it is a class
         * type hint, resolved against the current namespace. FilesystemStore::lock()
         * became `: VeriteIt\TraceItQr\Cache\mixed` and fatalled on return, at
         * runtime, only on 7.4, and only on the publish path. The 7.4 grammar
         * accepts it and PHP 8 treats it as the real builtin, so nothing short of
         * running the publish path on 7.4 could see it.
         */
        foreach (['type', 'returnType'] as $slot) {
            if (!isset($node->$slot)) {
                continue;
            }
            $t = $node->$slot;
            $name = null;
            if ($t instanceof Node\Identifier) {
                $name = $t->name;
            } elseif ($t instanceof Node\Name) {
                $name = $t->getLast();
            }
            if ($name !== null && in_array(strtolower($name), ['mixed', 'never'], true)) {
                $node->$slot = null;
                $this->notes[] = strtolower($name) . ' type removed';
            }
        }

        // non-capturing catch
        if ($node instanceof Node\Stmt\Catch_ && $node->var === null) {
            $node->var = new Node\Expr\Variable('__unused');
            $this->notes[] = 'catch variable added';
        }

        // nullsafe  $x?->y  ->  ($x === null ? null : $x->y)
        if ($node instanceof Node\Expr\NullsafePropertyFetch) {
            $this->notes[] = 'nullsafe expanded';
            return new Node\Expr\Ternary(
                new Node\Expr\BinaryOp\Identical($node->var, new Node\Expr\ConstFetch(new Node\Name('null'))),
                new Node\Expr\ConstFetch(new Node\Name('null')),
                new Node\Expr\PropertyFetch($node->var, $node->name)
            );
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        /*
         * match -> a chain of ternaries.
         *
         * A ternary chain rather than a switch, because match sits in expression
         * position ([$x, $y] = match (...)) and switch is a statement.
         *
         * match compares with ===, so the chain does too. The one semantic
         * difference is that match evaluates its subject once while the chain
         * repeats it, so this refuses anything that could have a side effect or
         * cost — a call, an assignment — rather than quietly changing behaviour.
         */
        if ($node instanceof Node\Expr\Match_) {
            $subject = $node->cond;
            $pure = $subject instanceof Node\Expr\Variable
                 || $subject instanceof Node\Expr\PropertyFetch
                 || $subject instanceof Node\Expr\ConstFetch
                 || $subject instanceof Node\Scalar\String_;
            if (!$pure) {
                throw new RuntimeException(
                    'match() on a non-trivial subject at line ' . $node->getLine()
                    . ' — a ternary chain would evaluate it more than once. Rewrite it in src/.'
                );
            }

            $default = null;
            $arms = [];
            foreach ($node->arms as $arm) {
                if ($arm->conds === null) { $default = $arm->body; continue; }
                $arms[] = $arm;
            }
            if ($default === null) {
                throw new RuntimeException(
                    'match() with no default at line ' . $node->getLine()
                    . ' — 7.4 has no UnhandledMatchError to fall back on.'
                );
            }

            $expr = $default;
            foreach (array_reverse($arms) as $arm) {
                $test = null;
                foreach ($arm->conds as $c) {
                    $cmp = new Node\Expr\BinaryOp\Identical(clone $subject, $c);
                    $test = $test === null ? $cmp : new Node\Expr\BinaryOp\BooleanOr($test, $cmp);
                }
                $expr = new Node\Expr\Ternary($test, $arm->body, $expr);
            }
            $this->notes[] = 'match expanded to ternaries';
            return $expr;
        }

        // readonly on plain properties
        if ($node instanceof Node\Stmt\Property && ($node->flags & Node\Stmt\Class_::MODIFIER_READONLY)) {
            $node->flags &= ~Node\Stmt\Class_::MODIFIER_READONLY;
            $this->notes[] = 'readonly dropped';
        }

        // constructor promotion -> explicit properties + assignments
        if ($node instanceof Node\Stmt\Class_) {
            foreach ($node->stmts as $m) {
                if (!($m instanceof Node\Stmt\ClassMethod) || $m->name->toString() !== '__construct') {
                    continue;
                }
                $props = [];
                $assign = [];
                foreach ($m->params as $p) {
                    if ($p->flags === 0) {
                        continue;
                    }
                    $name = (string) $p->var->name;
                    $flags = $p->flags & ~Node\Stmt\Class_::MODIFIER_READONLY;
                    if ($flags === 0) {
                        $flags = Node\Stmt\Class_::MODIFIER_PUBLIC;
                    }
                    $props[] = new Node\Stmt\Property(
                        $flags,
                        [new Node\Stmt\PropertyProperty($name)],
                        ['comments' => $p->getComments()],
                        $p->type instanceof Node\UnionType ? null : $p->type
                    );

                    /*
                     * `new` in a parameter default is 8.1 only. On 7.4 it is not a
                     * syntax error — the grammar accepts any expression there — but
                     * the compiler rejects it with "Constant expression contains
                     * invalid operations". So the default moves into the body:
                     *
                     *   Layout $l = new Layout()   ->   ?Layout $l = null
                     *                                   $this->l = $l ?? new Layout();
                     */
                    $value = new Node\Expr\Variable($name);
                    if ($p->default instanceof Node\Expr\New_) {
                        $value = new Node\Expr\BinaryOp\Coalesce($value, clone $p->default);
                        $p->default = new Node\Expr\ConstFetch(new Node\Name('null'));
                        if ($p->type !== null && !($p->type instanceof Node\NullableType)) {
                            $p->type = new Node\NullableType($p->type);
                        }
                        $this->notes[] = 'new-in-default moved into the constructor';
                    }

                    $assign[] = new Node\Stmt\Expression(new Node\Expr\Assign(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name),
                        $value
                    ));
                    $p->flags = 0;
                    $this->notes[] = 'promotion expanded';
                }
                if ($props) {
                    $m->stmts = array_merge($assign, $m->stmts ?? []);
                    array_splice($node->stmts, array_search($m, $node->stmts, true), 0, $props);
                }
            }
        }

        // named arguments -> positional
        if (($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\MethodCall
             || $node instanceof Node\Expr\StaticCall) && $node->args) {
            $named = false;
            foreach ($node->args as $a) {
                if ($a instanceof Node\Arg && $a->name !== null) { $named = true; break; }
            }
            if ($named) {
                $key = $this->resolveKey($node);
                if ($key === null || !isset($this->sigs[$key])) {
                    throw new RuntimeException(
                        'Named arguments at a call this script cannot resolve'
                        . ($key ? " ($key)" : '') . ' on line ' . $node->getLine()
                        . '. Refusing to guess the order.'
                    );
                }
                $order = $this->sigs[$key];
                $byName = [];
                $positional = [];
                foreach ($node->args as $a) {
                    if ($a->name === null) { $positional[] = $a; }
                    else { $byName[$a->name->toString()] = $a; }
                }

                /*
                 * Named arguments may skip over defaulted parameters — e.g.
                 * new self(fits: false, reason: $r) jumps nine of them. Positional
                 * form has no way to skip, so each gap is filled with that
                 * parameter's own declared default, taken from the AST rather than
                 * assumed. A gap with no default is genuinely inexpressible, and is
                 * refused rather than guessed.
                 */
                $rebuilt = $positional;
                $pending = [];                       // filled defaults, emitted only if a later arg needs them
                foreach ($order as $i => $param) {
                    $pname = $param['name'];
                    if ($i < count($positional)) { continue; }

                    if (isset($byName[$pname])) {
                        foreach ($pending as $d) { $rebuilt[] = $d; }
                        $pending = [];
                        $arg = $byName[$pname];
                        $rebuilt[] = new Node\Arg($arg->value, $arg->byRef, $arg->unpack, [], null);
                        unset($byName[$pname]);
                        continue;
                    }

                    if (!$byName) { break; }         // nothing later to place: trailing omissions are fine

                    if ($param['default'] === null) {
                        throw new RuntimeException(
                            "Named arguments skip required parameter '\$$pname' at $key on line "
                            . $node->getLine() . '. Not expressible positionally — fix src/ first.'
                        );
                    }
                    $pending[] = new Node\Arg(clone $param['default']);
                    $this->notes[] = 'default filled for a skipped parameter';
                }
                if ($byName) {
                    throw new RuntimeException(
                        'Unmatched named arguments at ' . $key . ': ' . implode(', ', array_keys($byName))
                    );
                }
                $node->args = $rebuilt;
                $this->notes[] = 'named args reordered';
            }
        }

        return null;
    }

    private function resolveKey(Node $node): ?string
    {
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $n = $node->class->toString();
            $n = $n === 'self' || $n === 'static' ? ($this->currentClass ?? '') : $n;
            $short = substr(strrchr('\\' . $n, '\\'), 1);
            return $short . '::__construct';
        }
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            foreach ($this->sigs as $k => $_) {
                if (str_ends_with($k, '::' . $node->name->toString())) { return $k; }
            }
        }
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier
            && $node->class instanceof Node\Name) {
            $n = $node->class->toString();
            $n = ($n === 'self' || $n === 'static') ? ($this->currentClass ?? '') : $n;
            $short = substr(strrchr('\\' . $n, '\\'), 1);
            return $short . '::' . $node->name->toString();
        }
        return null;
    }

    public ?string $currentClass = null;
}

// ── run ──────────────────────────────────────────────────────────────────
@mkdir($out . '/src/Cache', 0777, true);
$total = [];
$written = [];

foreach ($asts as $file => [$code, $stmts]) {
    // track the enclosing class so self:: resolves
    $cls = null;
    foreach ($stmts as $s) {
        if ($s instanceof Node\Stmt\Namespace_) {
            foreach ($s->stmts as $t) {
                if ($t instanceof Node\Stmt\Class_ && $t->name) { $cls = $t->name->toString(); }
            }
        }
    }

    $visitor = new Php74Visitor($sigs);
    $visitor->currentClass = $cls;
    $tr = new NodeTraverser();
    $tr->addVisitor($visitor);
    $new = $tr->traverse($stmts);

    foreach ($visitor->notes as $n) {
        $total[$n] = ($total[$n] ?? 0) + 1;
    }

    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $dest = $out . '/' . $rel;
    @mkdir(dirname($dest), 0777, true);

    $banner = "<?php\n\n/*\n * GENERATED — PHP 7.4 build. Do not edit.\n"
            . " * Source: $rel  ·  Regenerate: php tools/build-php74.php\n */\n";
    $body = $printer->prettyPrint($new);
    file_put_contents($dest, $banner . "\n" . $body . "\n");
    $written[] = $dest;
}

// ── polyfills ────────────────────────────────────────────────────────────
// str_contains and str_starts_with arrived in 8.0. Loaded through composer's
// "files" autoload, because PSR-4 cannot autoload plain functions.
file_put_contents($out . '/src/polyfill.php', <<<'PHP'
<?php

/*
 * GENERATED — PHP 7.4 build. Do not edit.
 *
 * String helpers added in PHP 8.0, which src/Client.php uses. Guarded so the
 * file is harmless if it is ever loaded on 8.x.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/*
 * Stringable arrived in 8.0, and PostId implements it. Declared rather than
 * stripped from the class, so `instanceof Stringable` keeps working for callers
 * on either build. PHP 8 makes any class with __toString() implement this
 * implicitly, which is why nothing has to change on the 8.1 side.
 *
 * Worth noting how this was found: it is not a syntax error, so parsing the
 * output under the 7.4 grammar said it was fine. Only running it on 7.4 did.
 */
if (!interface_exists('Stringable')) {
    interface Stringable
    {
        public function __toString(): string;
    }
}

PHP);

// ── composer.json for the 7.4 package ────────────────────────────────────
$cj = json_decode(file_get_contents($root . '/composer.json'), true);
$cj['name'] = 'veriteit/trace-it-qr-php74';
$cj['description'] = 'PHP 7.4 build of veriteit/trace-it-qr. Generated from the 8.1 source; do not edit by hand.';
$cj['require']['php'] = '>=7.4';
$cj['autoload']['files'] = ['src/polyfill.php'];

/*
 * Point support at THIS repository. Inherited from the 8.1 package, these sent a
 * 7.4 user to the 8.1 repo — which `composer show` prints as the package source,
 * so the one link a confused integrator clicks led to the build they cannot use.
 */
$cj['support'] = [
    'issues' => 'https://github.com/VeriteIT/Trace-it-qr-php74/issues',
    'source' => 'https://github.com/VeriteIT/Trace-it-qr-php74',
];
file_put_contents(
    $out . '/composer.json',
    json_encode($cj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

// ── check the output ─────────────────────────────────────────────────────
/*
 * Two checks over what was just written, both cheap and both local. Neither
 * replaces running the result on a real 7.4 interpreter — see tools/verify-php74.sh,
 * which is the authority and which has caught faults these two cannot.
 *
 * WHY TWO. Re-parsing under the 7.4 grammar is the obvious check and, on its own,
 * a weak one: php-parser's 7.4 parser rejects `readonly`, `match` and `enum`, but
 * happily accepts promoted constructor parameters, named arguments, union types,
 * nullsafe calls, non-capturing catch, `new` in an initialiser and first-class
 * callables. Seven of the nine transforms this generator performs could regress
 * without the grammar noticing.
 *
 * So the second check is the one that does the work: walk the output AST and fail
 * on any node that only exists in PHP 8. That asks the question actually worth
 * asking — did the transform leave anything behind — rather than whether a parser
 * happens to object.
 */
final class Php8Residue extends NodeVisitorAbstract
{
    public array $found = [];

    public function enterNode(Node $node)
    {
        $at = $node->getStartLine();

        if ($node instanceof Node\Param) {
            if ($node->flags !== 0) {
                $this->note('promoted constructor parameter', $at);
            }
            if ($node->default !== null
                && (new NodeFinder())->findFirstInstanceOf([$node->default], Node\Expr\New_::class)) {
                $this->note('new in a parameter default', $at);
            }
        }

        if ($node instanceof Node\Arg && $node->name !== null) {
            $this->note('named argument', $at);
        }

        if ($node instanceof Node\UnionType) {
            $this->note('union type', $at);
        }

        if ($node instanceof Node\IntersectionType) {
            $this->note('intersection type', $at);
        }

        if ($node instanceof Node\Expr\NullsafePropertyFetch
            || $node instanceof Node\Expr\NullsafeMethodCall) {
            $this->note('nullsafe operator', $at);
        }

        if ($node instanceof Node\Expr\Match_) {
            $this->note('match expression', $at);
        }

        if ($node instanceof Node\Stmt\Catch_ && $node->var === null) {
            $this->note('non-capturing catch', $at);
        }

        if ($node instanceof Node\Stmt\Enum_) {
            $this->note('enum', $at);
        }

        if ($node instanceof Node\VariadicPlaceholder) {
            $this->note('first-class callable', $at);
        }

        if ($node instanceof Node\AttributeGroup) {
            $this->note('attribute', $at);
        }

        // readonly on a property, a promoted parameter, or (8.2) a whole class
        foreach (['flags'] as $slot) {
            if (isset($node->$slot) && is_int($node->$slot)
                && ($node->$slot & Node\Stmt\Class_::MODIFIER_READONLY)) {
                $this->note('readonly', $at);
            }
        }

        /*
         * `mixed` (8.0) and `never` (8.1), in type position only — the same name
         * elsewhere is a method or property and means nothing here.
         *
         * Both spellings have to be checked, and missing that is what let the
         * FilesystemStore::lock() bug through. This scan reads the output back with
         * the 7.4 parser, and 7.4 has no such builtin — so `: mixed` comes back as a
         * Node\Name (a class called "mixed"), never a Node\Identifier. Checking only
         * Identifier meant the check could never fire on the one build it exists to
         * police.
         */
        foreach (['type', 'returnType'] as $slot) {
            if (!isset($node->$slot)) {
                continue;
            }
            $t = $node->$slot;
            $name = null;
            if ($t instanceof Node\Identifier) {
                $name = $t->name;
            } elseif ($t instanceof Node\Name) {
                $name = $t->getLast();
            }
            if ($name !== null && in_array(strtolower($name), ['mixed', 'never'], true)) {
                $this->note($name . ' type', $at);
            }
        }

        return null;
    }

    private function note(string $what, int $line): void
    {
        $this->found[] = sprintf('%s (line %d)', $what, $line);
    }
}

$written[] = $out . '/src/polyfill.php';
$bad = 0;

foreach ($written as $file) {
    $rel  = str_replace('\\', '/', substr($file, strlen($out) + 1));
    $code = file_get_contents($file);

    try {
        $stmts = $parser74->parse($code);
    } catch (PhpParser\Error $e) {
        echo "  REJECTED by the 7.4 grammar  $rel: " . $e->getMessage() . "\n";
        $bad++;
        continue;
    }

    $scan = new Php8Residue();
    $tr   = new NodeTraverser();
    $tr->addVisitor($scan);
    $tr->traverse($stmts);

    if ($scan->found !== []) {
        // One constructor is one line, so the same finding repeats per parameter.
        $seen = [];
        foreach (array_count_values($scan->found) as $what => $n) {
            $seen[] = $n > 1 ? $what . ' x' . $n : $what;
        }
        echo "  PHP 8 left in  $rel: " . implode(', ', $seen) . "\n";
        $bad++;
    }
}

if ($bad > 0) {
    fwrite(STDERR, "\n$bad generated file(s) are not PHP 7.4. Nothing downstream is trustworthy until that is fixed.\n");
    exit(1);
}

echo 'checked: ' . count($written) . " files parse as 7.4 and contain no PHP 8 constructs\n\n";

echo "transforms applied:\n";
foreach ($total as $k => $v) { printf("  %-24s %d\n", $k, $v); }
echo "\nfiles written: " . count($asts) . "\n";
