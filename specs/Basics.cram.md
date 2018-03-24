# General Tests

````sh
# Load functions and turn off error exit
    $ source jqmd; run-markdown "$TESTDIR/../imposer.md"; set +e
````

## Core Configuration

### File and Function Names

````sh
# Make . our project root
    $ cat >imposer-project.md <<'EOF'
    > ```shell
    > echo "hello from imposer-project.md!"
    > printf -v PHP_RUNTIME '%s\n' "${imposer_php[@]}"  # save the runtime
    > imposer_php=($'# imposer runtime goes here\n')    # blank it out
    > ```
    > EOF

# Ignore/null out site-wide configuration for testing
    $ loco_user_config() { :; }
    $ loco_site_config() { :; }
    $ imposer.no-op() { :;}
    $ loco_main no-op
    hello from imposer-project.md!

# Project directory should be current directory
    $ [[ "$LOCO_ROOT" == "$PWD" ]] || echo fail
````

### State Directories

State files are searched for in `IMPOSER_PATH` -- a `:`-separated string of directory names.  If no `IMPOSER_PATH` is set, one is generated that consists of:

* `./imposer`
* The Wordpress themes directory (e.g. `./wp-content/themes/`)
* The Wordpress plugin directory (e.g. `./wp-content/plugins/`)
* The `composer config vendor-dir`, if `composer.json` is present (e.g. `./vendor/`)
* The wp-cli package path (typically `~/.wp-cli/packages`)
* The global composer `vendor` directory, e.g. `${COMPOSER_HOME}/vendor`.

````sh
# Mock wp and composer
    $ exec 9>&2;
    $ wp() {
    >     case "$*" in
    >         "theme path") echo "themes";;
    >         "plugin path") echo "plugins";;
    >         "package path") echo "packages";;
    >         "eval-file - "*)
    >             echo "--- JSON: ---"; printf '%s\n' "${@:3}"
    >             echo "--- PHP: ---"; cat
    >             ;;
    >         *) echo "unexpected wp $*" >&9; exit 64 ;;
    >     esac
    > }
    $ composer() {
    >     case "$*" in
    >         "global config --absolute vendor-dir") echo "COMPOSER_GLOBAL_VENDOR";;
    >         "config --absolute vendor-dir") echo "vendor";;
    >         *) echo "unexpected composer $*" >&2; exit 64 ;;
    >     esac
    > }
````

#### `path` and `default-path`

You can run `imposer path` and `imposer default-path` to get the current set of state directories or the default set of directories, respectively:

````sh
# Default order is imposer, wp themes + plugins, composer local, wp packages, composer global:
    $ echo '{}' >composer.json
    $ mkdir -p imposer themes plugins packages vendor COMPOSER_GLOBAL_VENDOR
    $ (REPLY="$(imposer path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./themes:./plugins:./vendor:./packages:./COMPOSER_GLOBAL_VENDOR

# But can be overrriden by IMPOSER_PATH
    $ IMPOSER_PATH=vendor:imposer
    $ (REPLY="$(imposer path)"; echo "${REPLY//"$PWD"/.}")
    ./vendor:./imposer

# Unless you're looking at the default path (which ignores IMPOSER_PATH)
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./themes:./plugins:./vendor:./packages:./COMPOSER_GLOBAL_VENDOR

# Only directories that exist are included, however:
    $ rmdir COMPOSER_GLOBAL_VENDOR themes
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./plugins:./vendor:./packages

# And vendor/ is only included if there's a `composer.json`:
    $ rm composer.json
    $ (REPLY="$(imposer default-path)"; echo "${REPLY//"$PWD"/.}")
    ./imposer:./plugins:./packages
    $ echo '{}' >composer.json

# Once calculated, the internal path remains the same:
    $ IMPOSER_PATH=
    $ imposer path
    */imposer:*/plugins:*/vendor:*/packages (glob)

# even if IMPOSER_PATH changes, or a directory is removed:
    $ IMPOSER_PATH=vendor:imposer
    $ rmdir packages
    $ imposer path
    */imposer:*/plugins:*/vendor:*/packages (glob)

# But the default is still the default, and calculated "fresh":
    $ imposer default-path
    */imposer:*/plugins:*/vendor (glob)

# Reset for other tests
    $ unset IMPOSER_PATH
    $ imposer_dirs=()
````

## State Handling

### JSON and YAML

### Imposing Named States

States are imposed by sourcing the compiled form of their `.state.md` file, at most once.  States can require other states by calling `require` with one or more state names.

````sh
# Mock __find_state and __load_state
    $ old_states="$(declare -f __find_state __load_state)"
    $ __load_state() { echo "load state:" "$@"; }
    $ __find_state() { REPLY="found/$1"; echo "find state:" "$@"; }

# require loads the named state only once
    $ require fizz/buzz
    find state: fizz/buzz
    load state: fizz/buzz found/fizz/buzz
    $ require fizz/buzz

# infinite recursion is prevented
    $ __load_state() { echo "loading: $1"; require whiz/bang; }
    $ require ping/pong
    find state: ping/pong
    loading: ping/pong
    find state: whiz/bang
    loading: whiz/bang

# failure to find a state produces an error
    $ __find_state() { false; }
    $ (require cant/find)
    Could not find state cant/find in /*/imposer /*/plugins /*/vendor (glob)
    [64]

# Restore __find_state and __load_state
    $ eval "$old_states"
````

#### State File Lookup

States are looked up in each directory on the imposer path, checking for files in the exact directory  or specific sub-locations thereof:

````sh
# Mock file search function to output dir and files searched
    $ old_rie="$(declare -f reply_if_exists)"
    $ reply_if_exists() {
    >     [[ $1 == "$PWD/"* ]] || echo "invalid directory: $1"
    >     echo -n "${1/#$PWD/.} "; shift
    >     for REPLY; do [[ $REPLY == *.state.md ]] || echo "invalid filename: $REPLY"; done
    >     echo "${@%.state.md}"; false
    > }

    $ imposer path
    */imposer:*/plugins:*/vendor (glob)

# Paths for an unprefixed name:
    $ __find_state baz
    ./imposer baz baz/default baz/imposer-states/default ./imposer-states/baz
    ./plugins baz baz/default baz/imposer-states/default ./imposer-states/baz
    ./vendor baz baz/default baz/imposer-states/default ./imposer-states/baz
    [1]

    $ __find_state bar/baz
    ./imposer bar/baz bar/baz/default bar/baz/imposer-states/default bar/imposer-states/baz
    ./plugins bar/baz bar/baz/default bar/baz/imposer-states/default bar/imposer-states/baz
    ./vendor bar/baz bar/baz/default bar/baz/imposer-states/default bar/imposer-states/baz
    [1]

    $ __find_state foo/bar/baz
    ./imposer foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer-states/default foo/bar/imposer-states/baz
    ./plugins foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer-states/default foo/bar/imposer-states/baz
    ./vendor foo/bar/baz foo/bar/baz/default foo/bar/baz/imposer-states/default foo/bar/imposer-states/baz
    [1]

# Un-mock reply_if_exists
    $ eval "$old_rie"

# Non-existent state, return false:
    $ __find_state x
    [1]

# In last directory, name as file under imposer
    $ mkdir -p vendor/imposer-states
    $ touch vendor/imposer-states/x.state.md
    $ __find_state x && echo "$REPLY"
    /*/vendor/./imposer-states/x.state.md (glob)

# Override w/directory:
    $ mkdir -p vendor/x/imposer-states/
    $ touch vendor/x/imposer-states/default.state.md
    $ __find_state x && echo "$REPLY"
    /*/vendor/x/imposer-states/default.state.md (glob)

# Removing it exposes the previous file again
    $ rm vendor/x/imposer-states/default.state.md
    $ __find_state x && echo "$REPLY"
    /*/vendor/./imposer-states/x.state.md (glob)

````

#### State Loading

And then loaded by compiling the markdown source, optionally caching in the  `$IMPOSER_CACHE` directory (unless `IMPOSER_CACHE` is set to an empty string)

````sh
# Test cache generation
    $ cat >imposer/load-test.state.md <<'EOF'
    > ```shell
    > echo "loading load-test"
    > EOF

    $ __load_state load-test imposer/load-test.state.md
    loading load-test

    $ cat imposer/.cache/load-test
    echo "loading load-test"

# No caching if IMPOSER_CACHE is empty:
    $ rm imposer/.cache/load-test
    $ IMPOSER_CACHE= __load_state load-test imposer/load-test.state.md
    loading load-test
    $ cat imposer/.cache/load-test
    cat: *imposer/.cache/load-test*: No such file or directory (glob)
    [1]
````

### Processing JSON and PHP

After all required state files have been sourced, the accumulated YAML, JSON, and jq code they supplied is executed, to produce a JSON configuration.  All of the PHP code defined by this file and the state files is then run, with the JSON configuration as the `$state` variable.

````sh
# Running `imposer apply` calls `wp eval-file` with the accumulated JSON and PHP:
    $ event on "imposer_loaded" echo "EVENT: imposer_loaded"
    $ event on "json_loaded" echo "EVENT: json_loaded"
    $ event on "imposer_done" echo "EVENT: imposer_done"
    $ imposer apply
    EVENT: imposer_loaded
    EVENT: json_loaded
    --- JSON: ---
    {"options":{},"plugins":{}}
    --- PHP: ---
    <?php
    # imposer runtime goes here
    
    EVENT: imposer_done

# Running apply resets the filters and events, so doing it again is a no-op:
    $ imposer apply
````

#### Dumping JSON or PHP

The `imposer json` and `imposer php` commands process state files and then output the resulting JSON or PHP without running the PHP.  (Any shell code in the states is still executed, however.)

````sh
# Set up to run examples from README:
    $ cp $TESTDIR/../README.md imposer/dummy.state.md
    $ mkdir imposer/some; touch imposer/some/state.state.md
    $ mkdir imposer/foo; touch imposer/foo/other.state.md
    $ mkdir imposer/this; touch imposer/this/that.state.md
    $ export WP_FROM_EMAIL=foo@bar.com WP_FROM_NAME="Me"
    $ export MAILGUN_API_KEY=madeup\"key MAILGUN_API_DOMAIN=madeup.domain

# Run the version of imposer under test:
    $ imposer-cmd() { jqmd -R "$TESTDIR/../imposer.md" "$@"; }

# JSON dump:
    $ IMPOSER_PATH=imposer imposer-cmd json dummy
    hello from imposer-project.md!
    The project configuration has been loaded.
    The current state file (dummy) is finished loading.
    Just loaded a state called: dummy
    All states have finished loading.
    {
      "options": {
        "wp_mail_smtp": {
          "mail": {
            "from_email": "foo@bar.com",
            "from_name": "Me",
            "mailer": "mailgun",
            "return_path": true
          },
          "mailgun": {
            "api_key": "madeup\"key",
            "domain": "madeup.domain"
          }
        }
      },
      "plugins": {
        "disable_me": false,
        "wp_mail_smtp": null,
        "some-plugin": true
      },
      "my_ecommerce_plugin": {
        "categories": {},
        "products": {}
      }
    }

# PHP dump (includes only state-supplied code, no core code:
    $ IMPOSER_PATH=imposer imposer-cmd php dummy
    hello from imposer-project.md!
    The project configuration has been loaded.
    The current state file (dummy) is finished loading.
    Just loaded a state called: dummy
    All states have finished loading.
    <?php
    # imposer runtime goes here
    $my_plugin_info = $state['my_ecommerce_plugin'];
    
    MyPluginAPI::setup_products($my_plugin_info['products']);
    MyPluginAPI::setup_categories($my_plugin_info['categories']);
    
# And just for the heck of it, show all the events:
    $ wp() { echo wp "${@:1:2}"; cat >/dev/null; }; export -f wp
    $ IMPOSER_PATH=imposer imposer-cmd apply dummy
    hello from imposer-project.md!
    The project configuration has been loaded.
    The current state file (dummy) is finished loading.
    Just loaded a state called: dummy
    All states have finished loading.
    The JSON going to eval-file is:
    {"options":{"wp_mail_smtp":{"mail":{"from_email":"foo@bar.com","from_name":"Me","mailer":"mailgun","return_path":true},"mailgun":{"api_key":"madeup\"key","domain":"madeup.domain"}}},"plugins":{"disable_me":false,"wp_mail_smtp":null,"some-plugin":true},"my_ecommerce_plugin":{"categories":{},"products":{}}}
    wp eval-file -
    All PHP code has been run.

````