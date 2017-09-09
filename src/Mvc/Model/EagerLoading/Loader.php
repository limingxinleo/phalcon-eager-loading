<?php

namespace Xin\Phalcon\Mvc\Model\EagerLoading;

use Phalcon\Mvc\ModelInterface;
use Phalcon\Mvc\Model\Relation;
use Phalcon\Mvc\Model\Resultset\Simple;
use Phalcon\DI;

class Loader
{
    const E_INVALID_SUBJECT = 'Expected value of `subject` is either a ModelInterface object, a Simple object or an array of ModelInterface objects';

    /** @var ModelInterface[] 原模型实例数组 */
    protected $subject;
    /** @var string 模型类名 */
    protected $subjectClassName;
    /** @var array 需要Eager加载的实例 */
    protected $eagerLoads;
    /** @var boolean 是否返回一个模型 */
    protected $mustReturnAModel;

    /**
     * @param ModelInterface|ModelInterface[]|Simple $from
     * @param array                                  $arguments
     * @throws \InvalidArgumentException
     */

    public function __construct($from, ...$arguments)
    {
        $error = false;
        $className = null;
        if ($from instanceof ModelInterface) {
            $className = get_class($from);
            $from = [$from];

            $mustReturnAModel = true;
        } else if ($from instanceof Simple) {
            $from = $this->SimpleToArray($from);

            if (empty ($from)) {
                $from = null;
            } else {
                $className = get_class($from[0]);
            }

            $mustReturnAModel = false;

        } else if (($fromType = gettype($from)) !== 'array') {
            if (null !== $from && $fromType !== 'boolean') {
                $error = true;
            } else {
                $from = null;
            }
            $mustReturnAModel = false;

        } else {
            $from = array_filter($from);

            if (empty ($from)) {
                $from = null;
            } else {
                foreach ($from as $el) {
                    if ($el instanceof ModelInterface) {
                        if ($className === null) {
                            $className = get_class($el);
                        } else {
                            if ($className !== get_class($el)) {
                                $error = true;
                                break;
                            }
                        }
                    } else {
                        $error = true;
                        break;
                    }
                }
            }
            $mustReturnAModel = false;
        }

        if ($error) {
            throw new \InvalidArgumentException(static::E_INVALID_SUBJECT);
        }

        $this->subject = $from;
        $this->subjectClassName = $className;
        $this->eagerLoads = ($from === null || empty ($arguments)) ? [] : static::parseArguments($arguments);
        $this->mustReturnAModel = $mustReturnAModel;
    }

    /**
     * @desc   把Simple集合转化为数组
     * @author limx
     * @param Simple $from
     */
    private function SimpleToArray(Simple $from): array
    {
        $prev = $from;
        $from = [];
        foreach ($prev as $record) {
            $from[] = $record;
        }
        return $from;
    }

    /**
     * Create and get from a mixed $subject
     *
     * @param ModelInterface|ModelInterface[]|Simple $subject
     * @param mixed                                  ...$arguments
     * @throws \InvalidArgumentException
     * @return mixed
     */
    static public function from($subject, ...$arguments)
    {
        if ($subject instanceof ModelInterface) {
            $ret = static::fromModel($subject, ...$arguments);
        } else if ($subject instanceof Simple) {
            $ret = static::fromResultset($subject, ...$arguments);
        } else if (is_array($subject)) {
            $ret = static::fromArray($subject, ...$arguments);
        } else {
            throw new \InvalidArgumentException(static::E_INVALID_SUBJECT);
        }

        return $ret;
    }

    /**
     * Create and get from a Model
     *
     * @param ModelInterface $subject
     * @param mixed          ...$arguments
     * @return ModelInterface
     */
    static public function fromModel(ModelInterface $subject, ...$arguments)
    {
        return (new static($subject, ...$arguments))->execute()->get();
    }

    /**
     * Create and get from an array
     *
     * @param ModelInterface[] $subject
     * @param mixed            ...$arguments
     * @return array
     */
    static public function fromArray(array $subject, ...$arguments)
    {
        return (new static($subject, ...$arguments))->execute()->get();
    }

    /**
     * Create and get from a Resultset
     *
     * @param Simple $subject
     * @param mixed  ...$arguments
     * @return Simple
     */
    static public function fromResultset(Simple $subject, ...$arguments)
    {
        return (new static($subject, ...$arguments))->execute()->get();
    }

    /**
     * @return null|ModelInterface[]|ModelInterface
     */
    public function get()
    {
        $ret = $this->subject;

        if (null !== $ret && $this->mustReturnAModel) {
            $ret = $ret[0];
        }

        return $ret;
    }

    /**
     * @return null|ModelInterface[]
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Parses the arguments that will be resolved to Relation instances
     *
     * @param array $arguments
     * @throws \InvalidArgumentException
     * @return array
     */
    static private function parseArguments(array $arguments)
    {
        if (empty ($arguments)) {
            throw new \InvalidArgumentException('Arguments can not be empty');
        }

        $relations = [];

        if (count($arguments) === 1 && isset ($arguments[0]) && is_array($arguments[0])) {
            foreach ($arguments[0] as $relationAlias => $queryConstraints) {
                if (is_string($relationAlias)) {
                    $relations[$relationAlias] = is_callable($queryConstraints) ? $queryConstraints : null;
                } else {
                    if (is_string($queryConstraints)) {
                        $relations[$queryConstraints] = null;
                    }
                }
            }
        } else {
            foreach ($arguments as $relationAlias) {
                if (is_string($relationAlias)) {
                    $relations[$relationAlias] = null;
                }
            }
        }

        if (empty ($relations)) {
            throw new \InvalidArgumentException;
        }

        return $relations;
    }

    /**
     * @param string        $relationAlias
     * @param null|callable $constraints
     * @return $this
     */
    public function addEagerLoad($relationAlias, callable $constraints = null)
    {
        if (!is_string($relationAlias)) {
            throw new \InvalidArgumentException(sprintf(
                '$relationAlias expects to be a string, `%s` given',
                gettype($relationAlias)
            ));
        }

        $this->eagerLoads[$relationAlias] = $constraints;

        return $this;
    }

    /**
     * Resolves the relations
     *
     * @throws \RuntimeException
     * @return EagerLoad[]
     */
    private function buildTree()
    {
        uksort($this->eagerLoads, 'strcmp');

        $di = DI::getDefault();
        $mM = $di['modelsManager'];

        $eagerLoads = $resolvedRelations = [];

        foreach ($this->eagerLoads as $relationAliases => $queryConstraints) {
            $nestingLevel = 0;
            $relationAliases = explode('.', $relationAliases);
            $nestingLevels = count($relationAliases);

            // dd($relationAliases);
            do {
                do {
                    $alias = $relationAliases[$nestingLevel];
                    $name = join('.', array_slice($relationAliases, 0, $nestingLevel + 1));
                } while (isset ($eagerLoads[$name]) && ++$nestingLevel);
                // dd($nestingLevel);

                if ($nestingLevel === 0) {
                    $parentClassName = $this->subjectClassName;
                } else {
                    $parentName = join('.', array_slice($relationAliases, 0, $nestingLevel));
                    $parentClassName = $resolvedRelations[$parentName]->getReferencedModel();

                    if ($parentClassName[0] === '\\') {
                        ltrim($parentClassName, '\\');
                    }
                }

                // dd($resolvedRelations);
                if (!isset ($resolvedRelations[$name])) {
                    $mM->load($parentClassName);
                    $relation = $mM->getRelationByAlias($parentClassName, $alias);

                    if (!$relation instanceof Relation) {
                        throw new \RuntimeException(sprintf(
                            'There is no defined relation for the model `%s` using alias `%s`',
                            $parentClassName,
                            $alias
                        ));
                    }

                    $resolvedRelations[$name] = $relation;
                } else {
                    $relation = $resolvedRelations[$name];
                }

                $relType = $relation->getType();

                if ($relType !== Relation::BELONGS_TO &&
                    $relType !== Relation::HAS_ONE &&
                    $relType !== Relation::HAS_MANY &&
                    $relType !== Relation::HAS_MANY_THROUGH
                ) {

                    throw new \RuntimeException(sprintf('Unknown relation type `%s`', $relType));
                }

                if (is_array($relation->getFields()) ||
                    is_array($relation->getReferencedFields())
                ) {

                    throw new \RuntimeException('Relations with composite keys are not supported');
                }

                $parent = $nestingLevel > 0 ? $eagerLoads[$parentName] : $this;
                $constraints = $nestingLevel + 1 === $nestingLevels ? $queryConstraints : null;

                $eagerLoads[$name] = new EagerLoad($relation, $constraints, $parent);
            } while (++$nestingLevel < $nestingLevels);
        }

        return $eagerLoads;
    }

    /**
     * @return $this
     */
    public function execute()
    {
        foreach ($this->buildTree() as $eagerLoad) {
            $eagerLoad->load();
        }

        return $this;
    }

    /**
     * Loader::execute() alias
     *
     * @return $this
     */
    public function load()
    {
        foreach ($this->buildTree() as $eagerLoad) {
            $eagerLoad->load();
        }

        return $this;
    }
}
