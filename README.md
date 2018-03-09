# Terminus Plugin: Dibs

Dibs is a Terminus plugin for "calling dibs" on site environments. It can be
useful for teams who are working together on a limited number of multidevs, or
in the context of build automation and continuous integration.


## Installation

The simplest way to install this plugin is via Composer! Run the following
command to install this plugin:

```sh
composer create-project --stability=beta -d ~/.terminus/plugins/ terminus-plugin-project/terminus-dibs-plugin:~1
```

If you do not wish to use Composer, place the contents of this repository into
`~/.terminus/plugins/dibs` or the location of your `$TERMINUS_PLUGINS_DIR`. You
may do so either by cloning this repository using git, or by un-compressing the
tarball from a release on GitHub.

Verify that installation succeeded by running `terminus help env:dibs`


## Usage

### Dibs'ing a specific environment

To call dibs on the `dev` environment on a site called `your-site` run:

```sh
terminus env:dibs your-site.dev "Need the dev environment for a thing."
```

If the call succeeded, you should see a message like the following:

```
[notice] Called dibs on the dev environment.
```

...In addition to details about the environment.

Note that you must leave a note when calling dibs. If you or anyone else on your
team attempt to call dibs on `dev` again, you'll see an error containing the
message originally used to call dibs. Be sure to leave meaningful notes for your
colleagues!

### Un-dibs'ing an environment

Once you're done using your environment, you can _undibs_ it by running the
following command:

```sh
terminus env:undibs your-site.env
```

If the call succeeded, you should see a message like the following:

```
[notice] Undibs'd the dev environment.
```

...In addition to details about the environment.

Afterward, you or anyone else on your team may call dibs on `dev` again.

### Dibs'ing any available environment

If you don't care which environment you call, you may run the following command,
which doesn't require an environment name. Dibs will attempt to find an
environment that hasn't already been called.

```sh
terminus site:dibs your-site "Testing some layout tweaks"
```

If an environment was found, you'll see the same success message as shown above,
including the name of the dibs'd environment. Additionally, details about the
dibs'd environment will be returned.

If all environments are spoken for, you'll see an error message

```
[error] Unable to find an environment to dibs.
```

By default, all environments except for the live environment may be dibs'd.

### Dibs'ing an environment based on a filter

If you'd like to dibs an environment, but wish to limit the environments made
available for dibs'ing, you can do so by providing a regex pattern as a filter.

```sh
terminus site:dibs your-site "Experiments" '^((?!^(dev|test|live)$).)*$'
```

The above command would call dibs on a multidev, ignoring the `dev`, `test`, and
`live` environments.

Note: In both cases where no specific environment is provided, only those
environments that are fully spun-up are dibs'able. If you need to dibs an
environment as it is being spun up, specify the environment name.

### Dibs Report

If you want to see an overview of environments and their dibs status, you can
get an environment-by-environment breakdown using the following command:

```sh
terminus site:dibs:report your-site
```

Doing so, you'd get a response like this:

```
 ------------- ---------------- ---------- ------------------------ --------------------------
  Environment   Status           By         At                       Message
 ------------- ---------------- ---------- ------------------------ --------------------------
  dev           Available
  test          Not Ready
  multidev-1    Already called   username   Thu Nov 3rd at 07:23pm   New feature for the boss
 ------------- ---------------- ---------- ------------------------ --------------------------
```

Note that you can also supply a regex filter to limit the environments returned
in the report:

```sh
terminus site:dibs:report your-site '^((?!^(dev|test|live)$).)*$'
```

You can also use a flag, ```--older-than```, to further filter down environments by how old (in seconds) the dibs are:

```sh
terminus site:dibs:report tableau '^((?!^(dev|test|live)$).)*$' --older-than=1800
```


## Use-cases

This plugin assumes that you have persistent or semi-persistent environments
spun up on your Pantheon site. It can be useful for a variety of use-cases, both
human and automated.

### Poor man's multidev

Suppose you have a team of two or more and you're working for a client who is
too stingy for multidevs. If two of you want to try out new configurations in
the same area of the site, how do you figure out who uses `dev` vs. `test`?

This plugin can help manage work!

```sh
terminus env:dibs your-site.test "New features for the boss"
```

### Speed up CI builds

Suppose you run automated tests on a CI server that spin up and tear down
multidev environments, but the database is so large that a site create takes
_forever_.

Use this plugin to speed up your builds! Keep a handful of persistent CI
environments around, named using a convention like `ci1`, `ci2`, etc. Instead of
spinning up/tearing down environments, just call dibs!

```sh
export PENV=`terminus site:dibs --field=id -- your-site "Using env for build." '^ci\d$'`
```

Note you can also specify multiple fields (`--fields=id,domain`) (rather than
one) or specify an alternative format (`--format=json`) if desired. For more
details, run: `terminus help site:dibs`

### Multidev management

Suppose you have a large team or a large number of features you're working on
simultaneously, but only a handful of multidev environments.

Use this plugin to keep everyone from stepping on each other's toes! Keep your
multidev count at max capacity, named using a convention like `dev1`, `dev2`,
etc. Call dibs on an environment before you start working on a feature or as
soon as it's ready for QA.

```sh
terminus site:dibs your-site "Feature name/number" '^dev\d+$'
```

## Internals

In order to maintain state about whether or not an environment is _dibs'd_, this
plugin writes a small JSON file to a publicly accessible location in the site
environment's file system. If you run any file-based workflow operations (like
cloning from `live` to your dibs'd environment), dibs state will be lost.

This plugin _is_ smart enough to recognize when an environment has been created
from a previously dibs'd environment (e.g. a clone of db/files from one
environment to another), and will allow the target site to be dibs'd (like if
`dev` was already dib's and you spun up `multidev-1` from `dev`, even though the
dibs JSON from `dev` would exist on `multidev-1`, this plugin will still allow
you to call dibs on `multidev-1` anyway).
