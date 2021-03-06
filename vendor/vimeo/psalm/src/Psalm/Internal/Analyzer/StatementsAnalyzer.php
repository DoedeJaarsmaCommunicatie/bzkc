<?php
namespace Psalm\Internal\Analyzer;

use PhpParser;
use Psalm\Internal\Analyzer\Statements\Block\DoAnalyzer;
use Psalm\Internal\Analyzer\Statements\Block\ForAnalyzer;
use Psalm\Internal\Analyzer\Statements\Block\ForeachAnalyzer;
use Psalm\Internal\Analyzer\Statements\Block\IfAnalyzer;
use Psalm\Internal\Analyzer\Statements\Block\SwitchAnalyzer;
use Psalm\Internal\Analyzer\Statements\Block\TryAnalyzer;
use Psalm\Internal\Analyzer\Statements\Block\WhileAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\InstancePropertyAssignmentAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\ClassConstFetchAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\ConstFetchAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\VariableFetchAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\SimpleTypeInferer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\Statements\ReturnAnalyzer;
use Psalm\Internal\Analyzer\Statements\ThrowAnalyzer;
use Psalm\Internal\Scanner\ParsedDocblock;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;
use Psalm\FileManipulation;
use Psalm\Internal\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\Trace;
use Psalm\Issue\UndefinedTrace;
use Psalm\Issue\UnevaluatedCode;
use Psalm\Issue\UnrecognizedStatement;
use Psalm\Issue\UnusedVariable;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use function strtolower;
use function fwrite;
use const STDERR;
use function array_filter;
use function array_map;
use function array_merge;
use function preg_split;
use function get_class;
use function strrpos;
use function strlen;
use function substr;
use function array_key_exists;
use function array_change_key_case;
use function array_reverse;
use function trim;
use function array_column;
use function array_combine;

/**
 * @internal
 */
class StatementsAnalyzer extends SourceAnalyzer implements StatementsSource
{
    /**
     * @var SourceAnalyzer
     */
    protected $source;

    /**
     * @var FileAnalyzer
     */
    protected $file_analyzer;

    /**
     * @var Codebase
     */
    protected $codebase;

    /**
     * @var array<string, CodeLocation>
     */
    private $all_vars = [];

    /**
     * @var array<string, int>
     */
    private $var_branch_points = [];

    /**
     * Possibly undefined variables should be initialised if we're altering code
     *
     * @var array<string, int>|null
     */
    private $vars_to_initialize;

    /**
     * @var array<string, FunctionAnalyzer>
     */
    private $function_analyzers = [];

    /**
     * @var array<string, array{0: string, 1: CodeLocation}>
     */
    private $unused_var_locations = [];

    /**
     * @var array<string, bool>
     */
    private $used_var_locations = [];

    /**
     * @var ?array<string, bool>
     */
    public $byref_uses;

    /**
     * @var ParsedDocblock|null
     */
    private $parsed_docblock = null;

    /**
     * @var ?string
     */
    private $fake_this_class = null;

    /** @var \Psalm\Internal\Provider\NodeDataProvider */
    public $node_data;

    /**
     * @param SourceAnalyzer $source
     */
    public function __construct(SourceAnalyzer $source, \Psalm\Internal\Provider\NodeDataProvider $node_data)
    {
        $this->source = $source;
        $this->file_analyzer = $source->getFileAnalyzer();
        $this->codebase = $source->getCodebase();
        $this->node_data = $node_data;
    }

    /**
     * Checks an array of statements for validity
     *
     * @param  array<PhpParser\Node\Stmt>   $stmts
     * @param  Context                                          $context
     * @param  Context|null                                     $global_context
     * @param  bool                                             $root_scope
     *
     * @return null|false
     */
    public function analyze(
        array $stmts,
        Context $context,
        Context $global_context = null,
        $root_scope = false
    ) {
        // hoist functions to the top
        $this->hoistFunctions($stmts);

        $project_analyzer = $this->getFileAnalyzer()->project_analyzer;
        $codebase = $project_analyzer->getCodebase();

        if ($codebase->config->hoist_constants) {
            self::hoistConstants($this, $stmts, $context);
        }

        foreach ($stmts as $stmt) {
            if (self::analyzeStatement($this, $stmt, $context, $global_context) === false) {
                return false;
            }
        }

        if ($root_scope
            && !$context->collect_initializations
            && $codebase->find_unused_variables
            && $context->check_variables
        ) {
            $this->checkUnreferencedVars($stmts);
        }

        if ($codebase->alter_code && $root_scope && $this->vars_to_initialize) {
            $file_contents = $codebase->getFileContents($this->getFilePath());

            foreach ($this->vars_to_initialize as $var_id => $branch_point) {
                $newline_pos = (int)strrpos($file_contents, "\n", $branch_point - strlen($file_contents)) + 1;
                $indentation = substr($file_contents, $newline_pos, $branch_point - $newline_pos);
                FileManipulationBuffer::add($this->getFilePath(), [
                    new FileManipulation($branch_point, $branch_point, $var_id . ' = null;' . "\n" . $indentation),
                ]);
            }
        }

        return null;
    }

    /**
     * @param  array<PhpParser\Node\Stmt>   $stmts
     */
    private function hoistFunctions(array $stmts) : void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Function_) {
                $function_name = strtolower($stmt->name->name);

                if ($ns = $this->getNamespace()) {
                    $fq_function_name = strtolower($ns) . '\\' . $function_name;
                } else {
                    $fq_function_name = $function_name;
                }

                try {
                    $function_analyzer = new FunctionAnalyzer($stmt, $this->source);
                    $this->function_analyzers[$fq_function_name] = $function_analyzer;
                } catch (\UnexpectedValueException $e) {
                    // do nothing
                }
            }
        }
    }

    /**
     * @param  array<PhpParser\Node\Stmt>   $stmts
     */
    private static function hoistConstants(
        StatementsAnalyzer $statements_analyzer,
        array $stmts,
        Context $context
    ) : void {
        $codebase = $statements_analyzer->getCodebase();

        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Const_) {
                foreach ($stmt->consts as $const) {
                    ConstFetchAnalyzer::setConstType(
                        $statements_analyzer,
                        $const->name->name,
                        SimpleTypeInferer::infer(
                            $codebase,
                            $statements_analyzer->node_data,
                            $const->value,
                            $statements_analyzer->getAliases(),
                            $statements_analyzer
                        ) ?: Type::getMixed(),
                        $context
                    );
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof PhpParser\Node\Expr\FuncCall
                && $stmt->expr->name instanceof PhpParser\Node\Name
                && $stmt->expr->name->parts === ['define']
                && isset($stmt->expr->args[1])
            ) {
                $const_name = ConstFetchAnalyzer::getConstName(
                    $stmt->expr->args[0]->value,
                    $statements_analyzer->node_data,
                    $codebase,
                    $statements_analyzer->getAliases()
                );

                if ($const_name !== null) {
                    ConstFetchAnalyzer::setConstType(
                        $statements_analyzer,
                        $const_name,
                        Statements\Expression\SimpleTypeInferer::infer(
                            $codebase,
                            $statements_analyzer->node_data,
                            $stmt->expr->args[1]->value,
                            $statements_analyzer->getAliases(),
                            $statements_analyzer
                        ) ?: Type::getMixed(),
                        $context
                    );
                }
            }
        }
    }

    /**
     * @psalm-return false|null
     */
    private static function analyzeStatement(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Stmt $stmt,
        Context $context,
        ?Context $global_context
    ) {
        $ignore_variable_property = false;
        $ignore_variable_method = false;

        $codebase = $statements_analyzer->getCodebase();

        if ($context->has_returned
            && !$context->collect_initializations
            && !$context->collect_mutations
            && !($stmt instanceof PhpParser\Node\Stmt\Nop)
            && !($stmt instanceof PhpParser\Node\Stmt\InlineHTML)
        ) {
            if ($codebase->find_unused_variables) {
                if (IssueBuffer::accepts(
                    new UnevaluatedCode(
                        'Expressions after return/throw/continue',
                        new CodeLocation($statements_analyzer->source, $stmt)
                    ),
                    $statements_analyzer->source->getSuppressedIssues()
                )) {
                    return false;
                }
            }

            return;
        }

        if ($statements_analyzer->getProjectAnalyzer()->debug_lines) {
            fwrite(STDERR, $statements_analyzer->getFilePath() . ':' . $stmt->getLine() . "\n");
        }

        /*
        if (isset($context->vars_in_scope['$array']) && !$stmt instanceof PhpParser\Node\Stmt\Nop) {
            var_dump($stmt->getLine(), $context->vars_in_scope['$array']);
        }
        */

        $new_issues = null;
        $traced_variables = [];

        if ($docblock = $stmt->getDocComment()) {
            $statements_analyzer->parseStatementDocblock($docblock, $stmt, $context);

            if (isset($statements_analyzer->parsed_docblock->tags['psalm-trace'])) {
                foreach ($statements_analyzer->parsed_docblock->tags['psalm-trace'] as $traced_variable_line) {
                    $possible_traced_variable_names = preg_split('/[\s]+/', $traced_variable_line);
                    if ($possible_traced_variable_names) {
                        $traced_variables = array_merge(
                            $traced_variables,
                            array_filter($possible_traced_variable_names)
                        );
                    }
                }
            }

            if (isset($statements_analyzer->parsed_docblock->tags['psalm-ignore-variable-method'])) {
                $context->ignore_variable_method = $ignore_variable_method = true;
            }

            if (isset($statements_analyzer->parsed_docblock->tags['psalm-ignore-variable-property'])) {
                $context->ignore_variable_property = $ignore_variable_property = true;
            }

            if (isset($statements_analyzer->parsed_docblock->tags['psalm-suppress'])) {
                $suppressed = array_filter(
                    array_map(
                        /**
                         * @param string $line
                         *
                         * @return string
                         */
                        function ($line) {
                            return preg_split('/[\s]+/', $line)[0];
                        },
                        $statements_analyzer->parsed_docblock->tags['psalm-suppress']
                    )
                );

                if ($suppressed) {
                    $new_issues = [];

                    foreach ($suppressed as $offset => $issue_type) {
                        $offset += $docblock->getFilePos();
                        $new_issues[$offset] = $issue_type;

                        if ($issue_type === 'InaccessibleMethod') {
                            continue;
                        }

                        if ($codebase->track_unused_suppressions) {
                            IssueBuffer::addUnusedSuppression(
                                $statements_analyzer->getFilePath(),
                                $offset,
                                $issue_type
                            );
                        }
                    }

                    $statements_analyzer->addSuppressedIssues($new_issues);
                }
            }
        } else {
            $statements_analyzer->parsed_docblock = null;
        }

        if ($stmt instanceof PhpParser\Node\Stmt\If_) {
            if (IfAnalyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\TryCatch) {
            if (TryAnalyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\For_) {
            if (ForAnalyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Foreach_) {
            if (ForeachAnalyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\While_) {
            if (WhileAnalyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Do_) {
            DoAnalyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Const_) {
            ConstFetchAnalyzer::analyzeConstAssignment($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Unset_) {
            Statements\UnsetAnalyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Return_) {
            ReturnAnalyzer::analyze($statements_analyzer, $stmt, $context);
            $context->has_returned = true;
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Throw_) {
            ThrowAnalyzer::analyze($statements_analyzer, $stmt, $context);
            $context->has_returned = true;
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Switch_) {
            SwitchAnalyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Break_) {
            Statements\BreakAnalyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Continue_) {
            Statements\ContinueAnalyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Static_) {
            Statements\StaticAnalyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Echo_) {
            if (Statements\EchoAnalyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Function_) {
            FunctionAnalyzer::analyzeStatement($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Expression) {
            if (ExpressionAnalyzer::analyze(
                $statements_analyzer,
                $stmt->expr,
                $context,
                false,
                $global_context,
                true
            ) === false) {
                return false;
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\InlineHTML) {
            // do nothing
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Global_) {
            Statements\GlobalAnalyzer::analyze($statements_analyzer, $stmt, $context, $global_context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Property) {
            InstancePropertyAssignmentAnalyzer::analyzeStatement($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\ClassConst) {
            ClassConstFetchAnalyzer::analyzeClassConstAssignment($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
            try {
                $class_analyzer = new ClassAnalyzer(
                    $stmt,
                    $statements_analyzer->source,
                    $stmt->name ? $stmt->name->name : null
                );

                $class_analyzer->analyze(null, $global_context);
            } catch (\InvalidArgumentException $e) {
                // disregard this exception, we'll likely see it elsewhere in the form
                // of an issue
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Nop) {
            Statements\NopAnalyzer::analyze($statements_analyzer, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Goto_) {
            // do nothing
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Label) {
            // do nothing
        } elseif ($stmt instanceof PhpParser\Node\Stmt\Declare_) {
            foreach ($stmt->declares as $declaration) {
                if ((string) $declaration->key === 'strict_types'
                    && $declaration->value instanceof PhpParser\Node\Scalar\LNumber
                    && $declaration->value->value === 1
                ) {
                    $context->strict_types = true;
                }
            }
        } elseif ($stmt instanceof PhpParser\Node\Stmt\HaltCompiler) {
            $context->has_returned = true;
        } else {
            if (IssueBuffer::accepts(
                new UnrecognizedStatement(
                    'Psalm does not understand ' . get_class($stmt),
                    new CodeLocation($statements_analyzer->source, $stmt)
                ),
                $statements_analyzer->getSuppressedIssues()
            )) {
                return false;
            }
        }

        $codebase = $statements_analyzer->getCodebase();

        $plugin_classes = $codebase->config->after_statement_checks;

        if ($plugin_classes) {
            $file_manipulations = [];

            foreach ($plugin_classes as $plugin_fq_class_name) {
                if ($plugin_fq_class_name::afterStatementAnalysis(
                    $stmt,
                    $context,
                    $statements_analyzer,
                    $codebase,
                    $file_manipulations
                ) === false) {
                    return false;
                }
            }

            if ($file_manipulations) {
                FileManipulationBuffer::add($statements_analyzer->getFilePath(), $file_manipulations);
            }
        }

        if ($new_issues) {
            $statements_analyzer->removeSuppressedIssues($new_issues);
        }

        if ($ignore_variable_property) {
            $context->ignore_variable_property = false;
        }

        if ($ignore_variable_method) {
            $context->ignore_variable_method = false;
        }

        foreach ($traced_variables as $traced_variable) {
            if (isset($context->vars_in_scope[$traced_variable])) {
                if (IssueBuffer::accepts(
                    new Trace(
                        $traced_variable . ': ' . $context->vars_in_scope[$traced_variable]->getId(),
                        new CodeLocation($statements_analyzer->source, $stmt)
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // fall through
                }
            } else {
                if (IssueBuffer::accepts(
                    new UndefinedTrace(
                        'Attempt to trace undefined variable ' . $traced_variable,
                        new CodeLocation($statements_analyzer->source, $stmt)
                    ),
                    $statements_analyzer->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }
    }

    private function parseStatementDocblock(
        PhpParser\Comment\Doc $docblock,
        PhpParser\Node\Stmt $stmt,
        Context $context
    ) : void {
        $codebase = $this->getCodebase();

        try {
            $this->parsed_docblock = DocComment::parsePreservingLength($docblock);
        } catch (DocblockParseException $e) {
            if (IssueBuffer::accepts(
                new InvalidDocblock(
                    (string)$e->getMessage(),
                    new CodeLocation($this->getSource(), $stmt, null, true)
                )
            )) {
                // fall through
            }

            $this->parsed_docblock = null;
        }

        $comments = $this->parsed_docblock;

        if (isset($comments->tags['psalm-scope-this'])) {
            $trimmed = trim(\reset($comments->tags['psalm-scope-this']));

            if (!$codebase->classExists($trimmed)) {
                if (IssueBuffer::accepts(
                    new \Psalm\Issue\UndefinedDocblockClass(
                        'Scope class ' . $trimmed . ' does not exist',
                        new CodeLocation($this->getSource(), $stmt, null, true),
                        $trimmed
                    )
                )) {
                    // fall through
                }
            } else {
                $this_type = Type::parseString($trimmed);
                $context->self = $trimmed;
                $context->vars_in_scope['$this'] = $this_type;
                $this->setFQCLN($trimmed);
            }
        }
    }

    /**
     * @param  array<PhpParser\Node\Stmt>   $stmts
     * @return void
     */
    public function checkUnreferencedVars(array $stmts)
    {
        $source = $this->getSource();
        $codebase = $source->getCodebase();
        $function_storage = $source instanceof FunctionLikeAnalyzer ? $source->getFunctionLikeStorage($this) : null;
        if ($codebase->alter_code) {
            // Reverse array to deal with chain of assignments
            $this->unused_var_locations = array_reverse($this->unused_var_locations, true);
        }
        $var_list = array_column($this->unused_var_locations, 0);
        $loc_list = array_column($this->unused_var_locations, 1);

        $project_analyzer = $this->getProjectAnalyzer();

        $unused_var_remover = new Statements\UnusedAssignmentRemover();

        foreach ($this->unused_var_locations as $hash => list($var_id, $original_location)) {
            if (substr($var_id, 0, 2) === '$_' || isset($this->used_var_locations[$hash])) {
                continue;
            }

            if ((!$function_storage
                || !array_key_exists(substr($var_id, 1), $function_storage->param_types))
                && !isset($this->byref_uses[$var_id])
                && !VariableFetchAnalyzer::isSuperGlobal($var_id)
            ) {
                $issue = new UnusedVariable(
                    'Variable ' . $var_id . ' is never referenced',
                    $original_location
                );

                if ($codebase->alter_code
                    && !$unused_var_remover->checkIfVarRemoved($var_id, $original_location)
                    && isset($project_analyzer->getIssuesToFix()['UnusedVariable'])
                    && !IssueBuffer::isSuppressed($issue, $this->getSuppressedIssues())
                ) {
                    $unused_var_remover->findUnusedAssignment(
                        $this->getCodebase(),
                        $stmts,
                        array_combine($var_list, $loc_list),
                        $var_id,
                        $original_location
                    );
                }

                if (IssueBuffer::accepts(
                    $issue,
                    $this->getSuppressedIssues(),
                    true
                )) {
                    // fall through
                }
            }
        }
    }

    /**
     * @param  string       $var_name
     *
     * @return bool
     */
    public function hasVariable($var_name)
    {
        return isset($this->all_vars[$var_name]);
    }

    /**
     * @param  string       $var_id
     * @param  CodeLocation $location
     * @param  int|null     $branch_point
     *
     * @return void
     */
    public function registerVariable($var_id, CodeLocation $location, $branch_point)
    {
        $this->all_vars[$var_id] = $location;

        if ($branch_point) {
            $this->var_branch_points[$var_id] = $branch_point;
        }

        $this->registerVariableAssignment($var_id, $location);
    }

    /**
     * @param  string       $var_id
     * @param  CodeLocation $location
     *
     * @return void
     */
    public function registerVariableAssignment($var_id, CodeLocation $location)
    {
        $this->unused_var_locations[$location->getHash()] = [$var_id, $location];
    }

    /**
     * @param array<string, CodeLocation> $locations
     * @return void
     */
    public function registerVariableUses(array $locations)
    {
        foreach ($locations as $hash => $_) {
            unset($this->unused_var_locations[$hash]);
            $this->used_var_locations[$hash] = true;
        }
    }

    /**
     * @return array<string, array{0: string, 1: CodeLocation}>
     */
    public function getUnusedVarLocations()
    {
        return \array_diff_key($this->unused_var_locations, $this->used_var_locations);
    }

    /**
     * The first appearance of the variable in this set of statements being evaluated
     *
     * @param  string  $var_id
     *
     * @return CodeLocation|null
     */
    public function getFirstAppearance($var_id)
    {
        return isset($this->all_vars[$var_id]) ? $this->all_vars[$var_id] : null;
    }

    /**
     * @param  string $var_id
     *
     * @return int|null
     */
    public function getBranchPoint($var_id)
    {
        return isset($this->var_branch_points[$var_id]) ? $this->var_branch_points[$var_id] : null;
    }

    /**
     * @param string $var_id
     * @param int    $branch_point
     *
     * @return void
     */
    public function addVariableInitialization($var_id, $branch_point)
    {
        $this->vars_to_initialize[$var_id] = $branch_point;
    }

    public function getFileAnalyzer() : FileAnalyzer
    {
        return $this->file_analyzer;
    }

    public function getCodebase() : Codebase
    {
        return $this->codebase;
    }

    /**
     * @return array<string, FunctionAnalyzer>
     */
    public function getFunctionAnalyzers()
    {
        return $this->function_analyzers;
    }

    /**
     * @param array<string, bool> $byref_uses
     * @return void
     */
    public function setByRefUses(array $byref_uses)
    {
        $this->byref_uses = $byref_uses;
    }

    /**
     * @return array<string, array<array-key, CodeLocation>>
     */
    public function getUncaughtThrows(Context $context)
    {
        $uncaught_throws = [];

        if ($context->collect_exceptions) {
            if ($context->possibly_thrown_exceptions) {
                $config = $this->codebase->config;
                $ignored_exceptions = array_change_key_case(
                    $context->is_global ?
                        $config->ignored_exceptions_in_global_scope :
                        $config->ignored_exceptions
                );
                $ignored_exceptions_and_descendants = array_change_key_case(
                    $context->is_global ?
                        $config->ignored_exceptions_and_descendants_in_global_scope :
                        $config->ignored_exceptions_and_descendants
                );

                foreach ($context->possibly_thrown_exceptions as $possibly_thrown_exception => $codelocations) {
                    if (isset($ignored_exceptions[strtolower($possibly_thrown_exception)])) {
                        continue;
                    }

                    $is_expected = false;

                    foreach ($ignored_exceptions_and_descendants as $expected_exception => $_) {
                        if ($expected_exception === strtolower($possibly_thrown_exception)
                            || $this->codebase->classExtends($possibly_thrown_exception, $expected_exception)
                        ) {
                            $is_expected = true;
                            break;
                        }
                    }

                    if (!$is_expected) {
                        $uncaught_throws[$possibly_thrown_exception] = $codelocations;
                    }
                }
            }
        }

        return $uncaught_throws;
    }

    public function getFunctionAnalyzer(string $function_id) : ?FunctionAnalyzer
    {
        return $this->function_analyzers[$function_id] ?? null;
    }

    public function getParsedDocblock() : ?ParsedDocblock
    {
        return $this->parsed_docblock;
    }

    public function getFQCLN()
    {
        if ($this->fake_this_class) {
            return $this->fake_this_class;
        }

        return parent::getFQCLN();
    }

    public function setFQCLN(string $fake_this_class) : void
    {
        $this->fake_this_class = $fake_this_class;
    }

    public function getNodeTypeProvider() : \Psalm\NodeTypeProvider
    {
        return $this->node_data;
    }
}
