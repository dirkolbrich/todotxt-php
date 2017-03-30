<?php
declare(strict_types=1);

namespace TodoTxt;

use TodoTxt\Context;
use TodoTxt\Collection;
use TodoTxt\MetaData;
use TodoTxt\Project;
use TodoTxt\Exceptions\EmptyTaskException;
use TodoTxt\Exceptions\EmptyStringException;
use TodoTxt\Exceptions\InvalidStringException;
use TodoTxt\Exceptions\CompletionParadoxException;
use TodoTxt\Exceptions\CannotCalculateAgeException;

/**
 * Encapsulates a single line of a todo.txt list.
 * Handles the parsing of contexts, projects and other info from a task.
 */
class Task
{
    /**
     * The md5 hash of the raw utf-8 encoded string
     *
     * @var string
     */
    protected $id;

    /**
     * The task as passed to the constructor
     *
     * @var string
     */
    protected $raw;

    /**
     * The task, sans priority, completion marker/date
     *
     * @var string
     */
    protected $task;

      /**
     * The date the task was created
     *
     * @var \DateTime|null
     */
    protected $creationDate = null;

    /**
     * @var bool
     */
    protected $complete = false;

    /**
     *
     * The date the task is completed
     *
     * @var \DateTime|null
     */
    protected $completionDate = null;

    /**
     * indicates if a task is due
     *
     * @var bool
     */
    protected $due = false;

    /**
     * The date the task is due
     *
     * @var \DateTime
     */
    protected $dueDate = null;

    /**
     * A single-character, uppercase priority, if found
     *
     * @var string
     */
    protected $priority = null;

    /**
     * A list of project names found (case-sensitive)
     *
     * @var Collection
     */
    public $projects;

    /**
     * A list of context names found (case-sensitive)
     *
     * @var Collection
     */
    public $contexts;

    /**
     * A map of meta-data, contained in the task
     *
     * @var Collection
     */
    public $metadata;

    /**
     * Create a new task from a raw line held in a todo.txt file.
     *
     * @param string $string A raw task line
     * @param Collection $collection type of collection used
     * @throws \EmptyStringException When $task is an empty string (or whitespace)
     */
    public function __construct(string $string = null, Collection $collection = null)
    {
        // set Collection object
        if ($collection == null) {
            $this->projects = new Collection();
            $this->contexts = new Collection();
            $this->metadata = new Collection();
        } else {
            $this->projects = $collection;
            $this->contexts = $collection;
            $this->metadata = $collection;
        }

        // handle string at instantiation
        if (!is_null($string)) {
            $string = $this->validateString($string);
            $this->id = $this->createId($string);
            $this->parse($string);
        }
    }

    /**
     * static constructor function
     *
     * @param string $string - a string representing the raw task
     * @return self
     */
    public static function withString(string $string): self
    {
        $task = new Task();

        $string = $task->validateString($string);
        $task->id = $task->createId($string);
        $task->parse($string);

        return $task;
    }

    /**
     * set the task
     *
     * @param string $string - a string representing the raw task
     * @return self
     */
    public function setTask(string $string): self
    {
        $string = $this->validateString($string);
        $this->id = $this->createId($string);
        $this->parse($string);

        return $this;
    }
    /**
     * Get the id of the task
     *
     * @return string|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the remainder of the task (sans completed marker, creation date and priority)
     *
     * @return string|null
     */
    public function getTask()
    {
        return $this->task;
    }

    /**
     * return the $priority of this task
     *
     * @return string|null
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * @return bool
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @return \DateTime|null
     */
    public function getCompletionDate()
    {
        return $this->isComplete() && isset($this->completionDate) ? $this->completionDate : null;
    }

    /**
     * set task to complete
     */
    public function complete(): self
    {
        $this->complete = true;
        $this->completionDate = new \DateTime("now");
        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * set task to uncomplete
     */
    public function uncomplete(): self
    {
        $this->complete = false;
        $this->completionDate = null;
        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getCreationDate()
    {
        return isset($this->creationDate) ? $this->creationDate : null;
    }

    /**
     * Returns the age of the task if the task has a creation date.
     *
     * @param \DateTime|string $endDate  - The end-date to use if the task
     * does not have a completion date. If this is null and the task
     * doesn't have a completion date the current date will be used.
     *
     * @return \DateInterval  - the age of the task.
     * @throws \CannotCalculateAgeException - If the task does not have a creation date
     * @throws \CompletionParadoxException - If the task is completed before the creation date
     */
    public function age($endDate = null)
    {
        if (!isset($this->creationDate)) {
            throw new CannotCalculateAgeException;
        }

        // Decide on an end-date to use - completionDate, then a
        // provided date, then the current date.
        $end = new \DateTime('now');
        if (isset($this->completionDate)) {
            $end = $this->completionDate;
        } elseif (!is_null($endDate)) {
            if (!($endDate instanceof \DateTime)) {
                $endDate = new \DateTime($endDate);
            }
            $end = $endDate;
        }

        $diff = $this->creationDate->diff($end);
        if ($diff->invert) {
            throw new CompletionParadoxException;
        }

        return $diff;
    }

    /**
     * @return bool
     */
    public function hasPriority(): bool
    {
        if (isset($this->priority)) {
            return true;
        }

        return false;
    }



    /**
     * set priority of a task, overrides old $priority
     *
     * @param string $priority
     * @throws InvalidStringException
     */
    public function setPriority(string $priority): self
    {
        if (!ctype_alpha($priority) || !ctype_upper($priority)) {
            throw new InvalidStringException;
        }
        $this->priority = $priority;
        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * unset $priority of task
     */
    public function unsetPriority(): self
    {
        $this->priority = null;
        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * increase $priority of task
     *
     * @param integer $step
     */
    public function increasePriority(int $step = 1): self
    {
        // if Priority already at highest
        if ($this->priority === 'A') {
            return;
        }

        // get $priority and set it one higher
        do {
            // decrementing character is not implemented in PHP
            $this->priority = chr(ord($this->priority) - 1);
            --$step;
        } while ($step > 0);

        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * decrease $priority of task
     *
     * @param integer $step
     */
    public function decreasePriority(int $step = 1): self
    {
        // if Priority already at lowest
        if ($this->priority === 'Z') {
            return;
        }

        // get $priority and set it one higher
        do {
            ++$this->priority;
            --$step;
        } while ($step > 0);

        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * @return bool
     */
    public function isDue(): bool
    {
        return $this->due;
    }

    /**
     * @return \DateTime
     */
    public function getDueDate()
    {
        return $this->isDue() && isset($this->dueDate) ? $this->dueDate : null;
    }

    /**
     * edit the complete task
     *
     * @param string $string
     * @throws EmptyStringException
     */
    public function edit(string $string): self
    {
        $string = $this->validateString($string);
        $this->parse($string);

        return $this;
    }

    /**
     * append $string to end of task
     *
     * @param string $string
     */
    public function append(string $string):self
    {
        $string = $this->validateString($string);

        $this->task .= ' ' . $string;
        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * prepend $string to beginning of task, but after any completion or creation markers
     *
     * @param string $string
     */
    public function prepend(string $string): self
    {
        $string = $this->validateString($string);

        $this->task = $string . ' ' . $this->task;
        $this->raw = $this->rebuildRawString();

        return $this;
    }

    /**
     * validate the string
     *
     * @param string $string
     * @return string
     */
    protected function validateString(string $string): string
    {
        $string = trim($string);
        if (strlen($string) == 0) {
            throw new EmptyStringException;
        }

        return $string;
    }

    /**
     * create the $id of the task, a md5 hash based on the utf-8 encoded raw string
     *
     * @param string $string
     * @return string
     */
    protected function createId(string $string): string
    {
        return md5(utf8_encode($string));
    }

    /**
     * Parse the raw task string into its components
     *
     * @param string $string
     * @throws EmptyTaskException
     */
    protected function parse(string $string)
    {
        $this->raw = $string;

        // Since each of these parts can occur sequentially and only at
        // the start of the string, pass the remainder of the task on.
        $result = $this->findCompleted($string);
        $result = $this->findPriority($result);
        $result = $this->findCreated($result);

        // validate rest of string if task exists
        $result = trim($result);
        if (strlen($result) == 0) {
            throw new EmptyTaskException;
        }
        $this->task = $result;

        // Find metadata held in the rest of the task
        $this->findProject($result);
        $this->findContext($result);
        $this->findMetadata($result);

        // if no metadata, no need to find a due date within them
        ($this->metadata->isEmpty()) ? : $this->findDue($result);
    }

    /**
     * Looks for a "x " marker, followed by a date.
     *
     * Complete tasks start with an X (case-insensitive), followed by a
     * space. The date of completion follows this (required).
     * Dates are formatted like YYYY-MM-DD.
     *
     * @param string $input String to check for completion.
     * @return string Returns the rest of the task, without this part.
     */
    protected function findCompleted(string $input): string
    {
        // Match a lower or uppercase X, followed by a space and a
        // YYYY-MM-DD formatted date, followed by another space.
        // Invalid dates can be caught but checked after.
        $pattern = "/^(X|x) (\d{4}-\d{2}-\d{2}) /";

        if (preg_match($pattern, $input, $matches) == 1) {
            // Rather than throwing exceptions around, silently bypass this
            try {
                $this->completionDate = new \DateTime($matches[2]);
            } catch (\Exception $e) {
                return $input;
            }

            $this->complete = true;
            return substr($input, strlen($matches[0]));
        }

        return $input;
    }

    /**
     * Find a priority marker.
     * Priorities are signified by an uppercase letter in parentheses.
     *
     * @param string $input Input string to check.
     * @return string Returns the rest of the task, without this part.
     */
    protected function findPriority(string $input): string
    {
        // Match one uppercase letter in brackers, followed by a space.
        $pattern = "/^\(([A-Z])\) /";
        if (preg_match($pattern, $input, $matches) == 1) {
            $this->priority = $matches[1];
            return substr($input, strlen($matches[0]));
        }

        return $input;
    }

     /**
     * Find a creation date.
     *
     * @param string $input Input string to check.
     * @return string Returns the rest of the task, without this part.
     */
    protected function findCreated(string $input): string
    {
        // Match a YYYY-MM-DD formatted date, followed by a space.
        // Invalid dates can be caught but checked after.
        $pattern = "/^(\d{4}-\d{2}-\d{2}) /";
        if (preg_match($pattern, $input, $matches) == 1) {
            // Rather than throwing exceptions around, silently bypass this
            try {
                $this->creationDate = new \DateTime($matches[1]);
            } catch (\Exception $e) {
                return $input;
            }
            return substr($input, strlen($matches[0]));
        }

        return $input;
    }

    /**
     * Find +projects within the task
     *
     * @param string $input Input string to check
     */
    protected function findProject(string $input)
    {
        // Match an + sign, any non-whitespace character, ending with
        // an alphanumeric or underscore, followed either by the end of
        // the string or by whitespace.
        $pattern = "/\+(?P<project>\S+\w)(?=\s|$)/";

        if (preg_match_all($pattern, $input, $matches) > 0) {
            foreach ($matches['project'] as $project) {
                $this->addProject($project);
            }
        }
    }

    /**
     * Find @contexts within the task
     *
     * @param string $input Input string to check
     */
    protected function findContext(string $input)
    {
        // Match an at-sign, any non-whitespace character, ending with
        // an alphanumeric or underscore, followed either by the end of
        // the string or by whitespace.
        $pattern = "/@(?P<context>\S+\w)(?=\s|$)/";

        if (preg_match_all($pattern, $input, $matches) > 0) {
            foreach ($matches['context'] as $context) {
                $this->addContext($context);
            }
        }
    }

    /**
     * Metadata can be held in the string in the format key:value.
     * This is usually used by add-ons, which provide their own
     * formatting rules for tasks.
     *
     * @param string $input Input string to check
     */
    protected function findMetadata(string $input)
    {
        // Match a word (alphanumeric+underscores), a colon, followed by
        // any non-whitespace character.
        $pattern = "/(?<=\s|^)(?P<key>\w+):(?P<value>\S+)(?=\s|$)/";

        if (preg_match_all($pattern, $input, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $this->addMetadata($match);
            }
        }
    }

    /**
     * Find a due date within the metadata.
     *
     * @return void
     */
    protected function findDue()
    {
        foreach ($this->metadata as $meta) {
            if ($meta->getKey() == 'due') {
                $this->due = true;
                $this->dueDate = new \DateTime($meta->getValue());
                return;
            }
        }
    }

    /**
     * Add a projects to the project list.
     * Using this method will prevent duplication in the array.
     *
     * @param string $project - project name.
     */
    protected function addProject(string $project)
    {
        // create a new project
        $project = new Project($project);

        if (!$this->projects->isEmpty()) {
            // validate if created project is already in list
            foreach ($this->projects as $item) {
                if ($item->getId() == $project->getId()) {
                    return;
                }
            }

            // using collection method - refactor!
            // $projects = $this->projects->filter(function($item) use ($project) {
            //     $item->getId() == $project->getId();
            // });
            // var_dump($projects);
        }

        $this->projects->add($project);
    }

    /**
     * Add a context to the list.
     * Using this method will prevent duplication in the array.
     *
     * @param string $context - context name
     */
    protected function addContext(string $context)
    {
        //create new context
        $context = new Context($context);

        if (!$this->contexts->isEmpty()) {
            foreach ($this->contexts as $item) {
                if ($item->getId() == $context->getId()) {
                    return;
                 }
            }
        }

        $this->contexts->add($context);
    }

    /**
     * Add a metadata to the list.
     *
     * @param array $regexMatches - Array of metadata keys and values.
     */
    protected function addMetadata(array $regexMatch)
    {
        // create new metadata
        $match = [
            'full'  => $regexMatch[0],
            'key'   => $regexMatch['key'],
            'value' => $regexMatch['value'],
        ];
        $metadata = new MetaData($match);

        // validate metadata for duplicate
        if (!$this->metadata->isEmpty()) {
            foreach ($this->metadata as $item) {
                if ($item->getId() == $metadata->getId()) {
                    return;
                 }
            }
        }

        $this->metadata->add($metadata);
    }


    /**
     * Re-build the raw task string.
     *
     * @return string The task as a todo.txt line.
     */
    protected function rebuildRawString(): string
    {
        $raw = '';
        if ($this->isComplete()) {
            $raw .= sprintf('x %s ', $this->completionDate->format("Y-m-d"));
        }

        if (isset($this->priority)) {
            $raw .= sprintf('(%s) ', strtoupper($this->priority));
        }

        if (isset($this->creationDate)) {
            $raw .= sprintf('%s ', $this->creationDate->format("Y-m-d"));
        }

        $raw .= $this->task;

        return $raw;
    }

    /**
     * Re-build the task string.
     *
     * @return string - The task as a todo.txt line.
     */
    public function __toString(): string
    {
        $task = $this->rebuildRawString();

        return $task;
    }
}
