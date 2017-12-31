<?php declare(strict_types=1);

namespace GitWrapper;

use GitWrapper\GitCommand;

/**
 * Interacts with a working copy.
 *
 * All commands executed via an instance of this class act on the working copy
 * that is set through the constructor.
 */
final class GitWorkingCopy
{
    /**
     * The GitWrapper object that likely instantiated this class.
     *
     * @var \GitWrapper\GitWrapper
     */
    protected $gitWrapper;

    /**
     * Path to the directory containing the working copy.
     *
     * @var string
     */
    protected $directory;

    /**
     * The output captured by the last run Git commnd(s).
     *
     * @var string
     */
    protected $output = '';

    /**
     * A boolean flagging whether the repository is cloned.
     *
     * If the variable is null, the a rudimentary check will be performed to see
     * if the directory looks like it is a working copy.
     *
     * @var bool|null
     */
    protected $cloned;

    public function __construct(GitWrapper $gitWrapper, string $directory)
    {
        $this->gitWrapper = $gitWrapper;
        $this->directory = $directory;
    }

    /**
     * Gets the output captured by the last run Git commnd(s).
     */
    public function __toString(): string
    {
        return $this->getOutput();
    }

    public function getWrapper(): GitWrapper
    {
        return $this->gitWrapper;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function getOutput(): string
    {
        $output = $this->output;
        $this->output = '';
        return $output;
    }

    /**
     * Clears the stored output captured by the last run Git command(s).
     */
    public function clearOutput(): void
    {
        $this->output = '';
    }

    /**
     * Manually sets the cloned flag.
     *
     * @param boolean $cloned Whether the repository is cloned into the directory or not.
     *
     */
    public function setCloned(bool $cloned): void
    {
        $this->cloned = (bool) $cloned;
    }

    /**
     * Checks whether a repository has already been cloned to this directory.
     *
     * If the flag is not set, test if it looks like we're at a git directory.
     */
    public function isCloned(): bool
    {
        if (! isset($this->cloned)) {
            $gitDir = $this->directory;
            if (is_dir($gitDir . '/.git')) {
                $gitDir .= '/.git';
            };
            $this->cloned = (is_dir($gitDir . '/objects') && is_dir($gitDir . '/refs') && is_file($gitDir . '/HEAD'));
        }

        return $this->cloned;
    }

    /**
     * Runs a Git command and captures the output.
     *
     * @param mixed[] $args The arguments passed to the command method.
     * @param bool $setDirectory Set the working directory, defaults to true.
     */
    public function run(array $args, bool $setDirectory = true): GitWorkingCopy
    {
        $command = call_user_func_array([GitCommand::class, 'getInstance'], $args);
        if ($setDirectory) {
            $command->setDirectory($this->directory);
        }

        $this->output .= $this->gitWrapper->run($command);
        return $this;
    }

    /**
     * Returns the output of a `git status -s` command.
     */
    public function getStatus(): string
    {
        return $this->gitWrapper->git('status -s', $this->directory);
    }

    /**
     * Returns true if there are changes to commit.
     */
    public function hasChanges(): bool
    {
        $output = $this->getStatus();
        return ! empty($output);
    }

    /**
     * Returns whether HEAD has a remote tracking branch.
     */
    public function isTracking(): bool
    {
        try {
            $this->run(['rev-parse', '@{u}']);
        } catch (GitException $gitException) {
            return false;
        }

        return true;
    }

    /**
     * Returns whether HEAD is up-to-date with its remote tracking branch.
     */
    public function isUpToDate(): bool
    {
        if (! $this->isTracking()) {
            throw new GitException(
                'Error: HEAD does not have a remote tracking branch. Cannot check if it is up-to-date.'
            );
        }

        $this->clearOutput();
        $mergeBase = (string) $this->run(['merge-base', '@', '@{u}']);
        $remoteSha = (string) $this->run(['rev-parse', '@{u}']);
        return $mergeBase === $remoteSha;
    }

    /**
     * Returns whether HEAD is ahead of its remote tracking branch.
     *
     * If this returns true it means that commits are present locally which have
     * not yet been pushed to the remote.
     */
    public function isAhead(): bool
    {
        if (! $this->isTracking()) {
            throw new GitException('Error: HEAD does not have a remote tracking branch. Cannot check if it is ahead.');
        }

        $this->clearOutput();
        $mergeBase = (string) $this->run(['merge-base', '@', '@{u}']);
        $localSha = (string) $this->run(['rev-parse', '@']);
        $remoteSha = (string) $this->run(['rev-parse', '@{u}']);
        return $mergeBase === $remoteSha && $localSha !== $remoteSha;
    }

    /**
     * Returns whether HEAD is behind its remote tracking branch.
     *
     * If this returns true it means that a pull is needed to bring the branch
     * up-to-date with the remote.
     */
    public function isBehind(): bool
    {
        if (! $this->isTracking()) {
            throw new GitException('Error: HEAD does not have a remote tracking branch. Cannot check if it is behind.');
        }

        $this->clearOutput();
        $mergeBase = (string) $this->run(['merge-base', '@', '@{u}']);
        $localSha = (string) $this->run(['rev-parse', '@']);
        $remoteSha = (string) $this->run(['rev-parse', '@{u}']);
        return $mergeBase === $localSha && $localSha !== $remoteSha;
    }

    /**
     * Returns whether HEAD needs to be merged with its remote tracking branch.
     *
     * If this returns true it means that HEAD has diverged from its remote
     * tracking branch; new commits are present locally as well as on the
     * remote.
     */
    public function needsMerge(): bool
    {
        if (! $this->isTracking()) {
            throw new GitException('Error: HEAD does not have a remote tracking branch. Cannot check if it is behind.');
        }

        $this->clearOutput();
        $mergeBase = (string) $this->run(['merge-base', '@', '@{u}']);
        $localSha = (string) $this->run(['rev-parse', '@']);
        $remoteSha = (string) $this->run(['rev-parse', '@{u}']);
        return $mergeBase !== $localSha && $mergeBase !== $remoteSha;
    }

    /**
     * Returns a GitBranches object containing information on the repository's
     * branches.
     */
    public function getBranches(): GitBranches
    {
        return new GitBranches($this);
    }

    /**
     * Helper method that pushes a tag to a repository.
     *
     * This is synonymous with `git push origin tag v1.2.3`.
     *
     * @param string $tag The tag being pushed.
     * @param string $repository The destination of the push operation, which is either a URL or name of
     *   the remote. Defaults to "origin".
     * @param mixed[] $options An associative array of command line options.
     */
    public function pushTag(string $tag, string $repository = 'origin', array $options = []): GitWorkingCopy
    {
        return $this->push($repository, 'tag', $tag, $options);
    }

    /**
     * Helper method that pushes all tags to a repository.
     *
     * This is synonymous with `git push --tags origin`.
     *
     * @param string $repository
     *   The destination of the push operation, which is either a URL or name of
     *   the remote. Defaults to "origin".
     * @param mixed[] $options An associative array of command line options.
     */
    public function pushTags(string $repository = 'origin', array $options = []): GitWorkingCopy
    {
        $options['tags'] = true;
        return $this->push($repository, $options);
    }

    /**
     * Fetches all remotes.
     *
     * This is synonymous with `git fetch --all`.
     *
     * @param mixed[] $options An associative array of command line options.
     */
    public function fetchAll(array $options = []): GitWorkingCopy
    {
        $options['all'] = true;
        return $this->fetch($options);
    }

    /**
     * Create a new branch and check it out.
     *
     * This is synonymous with `git checkout -b`.
     *
     * @param mixed[] $options
     */
    public function checkoutNewBranch(string $branch, array $options = []): GitWorkingCopy
    {
        $options['b'] = true;
        return $this->checkout($branch, $options);
    }

    /**
     * Adds a remote to the repository.
     *
     * @param string $name
     *   The name of the remote to add.
     * @param string $url
     *   The URL of the remote to add.
     * @param mixed[] $options
     *   An associative array of options, with the following keys:
     *   - -f: Boolean, set to true to run git fetch immediately after the
     *     remote is set up. Defaults to false.
     *   - --tags: Boolean. By default only the tags from the fetched branches
     *     are imported when git fetch is run. Set this to true to import every
     *     tag from the remote repository. Defaults to false.
     *   - --no-tags: Boolean, when set to true, git fetch does not import tags
     *     from the remote repository. Defaults to false.
     *   - -t: Optional array of branch names to track. If left empty, all
     *     branches will be tracked.
     *   - -m: Optional name of the master branch to track. This will set up a
     *     symbolic ref 'refs/remotes/<name>/HEAD which points at the specified
     *     master branch on the remote. When omitted, no symbolic ref will be
     *     created.
     *
     */
    public function addRemote(string $name, string $url, array $options = []): GitWorkingCopy
    {
        if (empty($name)) {
            throw new GitException('Cannot add remote without a name.');
        }

        if (empty($url)) {
            throw new GitException('Cannot add remote without a URL.');
        }

        $args = ['add'];

        // Add boolean options.
        foreach (['-f', '--tags', '--no-tags'] as $option) {
            if (! empty($options[$option])) {
                $args[] = $option;
            }
        }

        // Add tracking branches.
        if (! empty($options['-t'])) {
            foreach ($options['-t'] as $branch) {
                array_push($args, '-t', $branch);
            }
        }

        // Add master branch.
        if (! empty($options['-m'])) {
            array_push($args, '-m', $options['-m']);
        }

        // Add remote name and URL.
        array_push($args, $name, $url);

        return call_user_func_array([$this, 'remote'], $args);
    }

    /**
     * Removes the given remote.
     *
     * @param string $name
     *   The name of the remote to remove.
     *
     */
    public function removeRemote(string $name): GitWorkingCopy
    {
        return $this->remote('rm', $name);
    }

    /**
     * Checks if the given remote exists.
     *
     * @param string $name
     *   The name of the remote to check.
     *
     */
    public function hasRemote(string $name): bool
    {
        return array_key_exists($name, $this->getRemotes());
    }

    /**
     * @return string[] An associative array with the following keys:
     *  - fetch: the fetch URL.
     *  - push: the push URL.
     */
    public function getRemote(string $name): array
    {
        if (! $this->hasRemote($name)) {
            throw new GitException(sprintf('The remote "%s" does not exist.', $name));
        }

        $remotes = $this->getRemotes();

        return $remotes[$name];
    }

    /**
     * @return mixed[] An associative array, keyed by remote name, containing an associative array with keys:
     *  - fetch: the fetch URL.
     *  - push: the push URL.
     */
    public function getRemotes(): array
    {
        $this->clearOutput();

        $remotes = [];
        foreach (explode("\n", rtrim($this->remote()->getOutput())) as $remote) {
            $remotes[$remote]['fetch'] = $this->getRemoteUrl($remote);
            $remotes[$remote]['push'] = $this->getRemoteUrl($remote, 'push');
        }

        return $remotes;
    }

    /**
     * Returns the fetch or push URL of a given remote.
     *
     * @param string $remote The name of the remote for which to return the fetch or push URL.
     * @param string $operation The operation for which to return the remote. Can be either 'fetch' or 'push'.
     */
    public function getRemoteUrl(string $remote, string $operation = 'fetch'): string
    {
        $this->clearOutput();

        $args = $operation === 'push' ? ['get-url', '--push', $remote] : ['get-url', $remote];
        try {
            return rtrim(call_user_func_array([$this, 'remote'], $args)->getOutput());
        } catch (GitException $gitException) {
            // Fall back to parsing 'git remote -v' for older versions of git
            // that do not support `git remote get-url`.
            $identifier = " (${operation})";
            foreach (explode("\n", rtrim($this->remote('-v')->getOutput())) as $line) {
                if (strpos($line, $remote) === 0 && strrpos($line, $identifier) === strlen($line) - strlen($identifier)
                ) {
                    preg_match('/^.+\t(.+) \(' . $operation . '\)$/', $line, $matches);
                    return $matches[1];
                }
            }
        }
    }

    /**
     * Executes a `git add` command.
     *
     * Add file contents to the index.
     *
     * @code
     * $git->add('some/file.txt');
     *
     * @param string $filepattern Files to add content from. Fileglobs (e.g.  *.c) can be given to add
     *   all matching files. Also a leading directory name (e.g.  dir to add dir/file1 and dir/file2)
     *   can be given to add all files in the directory, recursively.
     * @param mixed[] $options An optional array of command line options.
     */
    public function add(string $filepattern, array $options = []): GitWorkingCopy
    {
        $args = [
            'add',
            $filepattern,
            $options,
        ];
        return $this->run($args);
    }

    /**
     * Executes a `git apply` command.
     *
     * Apply a patch to files and/or to the index
     *
     * @code
     * $git->apply('the/file/to/read/the/patch/from');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     */
    public function apply(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'apply');
        return $this->run($args);
    }

    /**
     * Executes a `git bisect` command.
     *
     * Find by binary search the change that introduced a bug.
     *
     * @code
     * $git->bisect('good', '2.6.13-rc2');
     * $git->bisect('view', array('stat' => true));
     *
     * @param string $sub_command The subcommand passed to `git bisect`.
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function bisect(string $sub_command): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'bisect');
        return $this->run($args);
    }

    /**
     * Executes a `git branch` command.
     *
     * List, create, or delete branches.
     *
     * @code $git->branch('my2.6.14', 'v2.6.14');
     * $git->branch('origin/html', 'origin/man', array('d' => true, 'r' => 'origin/todo'));
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function branch(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'branch');
        return $this->run($args);
    }

    /**
     * Executes a `git checkout` command.
     *
     * Checkout a branch or paths to the working tree.
     *
     * @code $git->checkout('new-branch', array('b' => true));
     *
     * @param string Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function checkout(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'checkout');
        return $this->run($args);
    }

    /**
     * Executes a `git clone` command.
     *
     * @code
     * $git->cloneRepository('git://github.com/cpliakas/git-wrapper.git');
     *
     * @param string $repository The Git URL of the repository being cloned.
     * @param mixed[] $options An associative array of command line options.
     * @param string $repository The URL of the repository being cloned.
     *
     */
    public function cloneRepository(string $repository, array $options = []): GitWorkingCopy
    {
        $args = [
            'clone',
            $repository,
            $this->directory,
            $options,
        ];
        return $this->run($args, false);
    }

    /**
     * Executes a `git commit` command.
     *
     * Record changes to the repository. If only one argument is passed, it is
     * assumed to be the commit message. Therefore `$git->commit('Message');`
     * yields a `git commit -am "Message"` command.
     *
     * @code $git->commit('My commit message');
     * $git->commit('Makefile', array('m' => 'My commit message'));
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function commit(): GitWorkingCopy
    {
        $args = func_get_args();
        if (isset($args[0]) && is_string($args[0]) && ! isset($args[1])) {
            $args[0] = [
                'm' => $args[0],
                'a' => true,
            ];
        }

        array_unshift($args, 'commit');
        return $this->run($args);
    }

    /**
     * Executes a `git config` command.
     *
     * Get and set repository options.
     *
     * @code $git->config('user.email', 'opensource@chrispliakas.com');
     * $git->config('user.name', 'Chris Pliakas');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function config(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'config');
        return $this->run($args);
    }

    /**
     * Executes a `git diff` command.
     *
     * Show changes between commits, commit and working tree, etc.
     *
     * @code $git->diff();
     * $git->diff('topic', 'master');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function diff(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'diff');
        return $this->run($args);
    }

    /**
     * Executes a `git fetch` command.
     *
     * Download objects and refs from another repository.
     *
     * @code $git->fetch('origin');
     * $git->fetch(array('all' => true));
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function fetch(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'fetch');
        return $this->run($args);
    }

    /**
     * Executes a `git grep` command.
     *
     * Print lines matching a pattern.
     *
     * @code $git->grep('time_t', '--', '*.[ch]');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function grep(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'grep');
        return $this->run($args);
    }

    /**
     * Executes a `git init` command.
     *
     * Create an empty git repository or reinitialize an existing one.
     *
     * @code
     * $git->init(array('bare' => true));
     *
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function init(array $options = []): GitWorkingCopy
    {
        $args = [
            'init',
            $this->directory,
            $options,
        ];

        return $this->run($args, false);
    }

    /**
     * Executes a `git log` command.
     *
     * Show commit logs.
     *
     * @code $git->log(array('no-merges' => true));
     * $git->log('v2.6.12..', 'include/scsi', 'drivers/scsi');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function log(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'log');
        return $this->run($args);
    }

    /**
     * Executes a `git merge` command.
     *
     * Join two or more development histories together.
     *
     * @code $git->merge('fixes', 'enhancements');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function merge(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'merge');
        return $this->run($args);
    }

    /**
     * Executes a `git mv` command.
     *
     * Move or rename a file, a directory, or a symlink.
     *
     * @code
     * $git->mv('orig.txt', 'dest.txt');
     *
     * @param string $source The file / directory being moved.
     * @param string $destination The target file / directory that the source is being move to.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function mv(string $source, string $destination, array $options = []): GitWorkingCopy
    {
        $args = [
            'mv',
            $source,
            $destination,
            $options,
        ];
        return $this->run($args);
    }

    /**
     * Executes a `git pull` command.
     *
     * Fetch from and merge with another repository or a local branch.
     *
     * @code $git->pull('upstream', 'master');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function pull(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'pull');
        return $this->run($args);
    }

    /**
     * Executes a `git push` command.
     *
     * Update remote refs along with associated objects.
     *
     * @code $git->push('upstream', 'master');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function push(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'push');
        return $this->run($args);
    }

    /**
     * Executes a `git rebase` command.
     *
     * Forward-port local commits to the updated upstream head.
     *
     * @code $git->rebase('subsystem@{1}', array('onto' => 'subsystem'));
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function rebase(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'rebase');
        return $this->run($args);
    }

    /**
     * Executes a `git remote` command.
     *
     * Manage the set of repositories ("remotes") whose branches you track.
     *
     * @code $git->remote('add', 'upstream', 'git://github.com/cpliakas/git-wrapper.git');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function remote(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'remote');
        return $this->run($args);
    }

    /**
     * Executes a `git reset` command.
     *
     * Reset current HEAD to the specified state.
     *
     * @code $git->reset(array('hard' => true));
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function reset(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'reset');
        return $this->run($args);
    }

    /**
     * Executes a `git rm` command.
     *
     * Remove files from the working tree and from the index.
     *
     * @code
     * $git->rm('oldfile.txt');
     *
     * @param string $filepattern Files to remove from version control. Fileglobs (e.g.  *.c) can be
     *   given to add all matching files. Also a leading directory name (e.g.
     *   dir to add dir/file1 and dir/file2) can be given to add all files in
     *   the directory, recursively.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function rm(string $filepattern, array $options = []): GitWorkingCopy
    {
        $args = [
            'rm',
            $filepattern,
            $options,
        ];
        return $this->run($args);
    }

    /**
     * Executes a `git show` command.
     *
     * Show various types of objects.
     *
     * @code
     * $git->show('v1.0.0');
     *
     * @param string $object The names of objects to show. For a more complete list of ways to spell
     *   object names, see "SPECIFYING REVISIONS" section in gitrevisions(7).
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function show(string $object, array $options = []): GitWorkingCopy
    {
        $args = ['show', $object, $options];
        return $this->run($args);
    }

    /**
     * Executes a `git status` command.
     *
     * Show the working tree status.
     *
     * @code $git->status(array('s' => true));
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function status(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'status');
        return $this->run($args);
    }

    /**
     * Executes a `git tag` command.
     *
     * Create, list, delete or verify a tag object signed with GPG.
     *
     * @code $git->tag('v1.0.0');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function tag(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'tag');
        return $this->run($args);
    }

    /**
     * Executes a `git clean` command.
     *
     * Remove untracked files from the working tree
     *
     * @code $git->clean('-d', '-f');
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function clean(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'clean');
        return $this->run($args);
    }

    /**
     * Executes a `git archive` command.
     *
     * Create an archive of files from a named tree
     *
     * @code $git->archive('HEAD', array('o' => '/path/to/archive'));
     *
     * @param string ... Additional command line arguments.
     * @param mixed[] $options An associative array of command line options.
     *
     */
    public function archive(): GitWorkingCopy
    {
        $args = func_get_args();
        array_unshift($args, 'archive');
        return $this->run($args);
    }

    /**
     * Returns a GitTags object containing  information on the repository's tags.
     */
    public function tags(): GitTags
    {
        return new GitTags($this);
    }
}
