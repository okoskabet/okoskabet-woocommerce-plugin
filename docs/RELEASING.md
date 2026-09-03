# Releasing the plugin

Merchants get updates through [Plugin Update Checker][puc], which reads this
repository directly. There is no wordpress.org listing and no separate update
server.

## How a merchant is offered an update

`okoskabet-woocommerce-plugin.php` builds the update checker without naming a
branch, so it uses the library's default, `master`. That name is what makes the
library look for a **release** first:

1. the latest GitHub release, then
2. the tag with the highest version number, then
3. the branch itself.

Today there are no releases and no tags, so every merchant is served whatever
sits at the head of `main`. (There is no `master` branch either — GitHub still
redirects the old name, which is the only reason step 3 resolves at all.)

**The moment the first release exists, step 1 takes over.** From then on, `main`
can receive merged work without offering anything to anyone, and a release is a
deliberate act.

## Cutting a release

1. Merge the work to `main` as usual. Nothing reaches merchants yet.
2. Set the `Version:` header in `okoskabet-woocommerce-plugin.php` **and** the
   `O_VERSION` constant just below it.
3. Add the matching section to `CHANGELOG.md`, headed `## 1.5.0 - YYYY-MM-DD`.
   It becomes the release notes, so write it for merchants rather than for us.
4. Tag it and push the tag:

   ```bash
   git tag v1.5.0
   git push origin v1.5.0
   ```

`.github/workflows/release.yml` does the rest: it refuses to publish if the tag
and the header disagree, builds the plugin zip, and attaches it to the release.

## Two things that bite

**WordPress reads the version from the header, never from the tag.** Tag
`v1.5.0` while the header still says `1.4.9` and every merchant is offered
1.4.9, with nothing anywhere explaining why. The workflow fails the release
rather than let that happen.

**Without an attached zip, merchants get the whole repository.** The update
checker falls back to GitHub's auto-generated source archive, which would put
`tests/`, the build config and everything else into `wp-content/plugins`. The
workflow attaches the same lean zip we install on staging, and
`enableReleaseAssets()` in the plugin file tells the checker to prefer it.
`.gitattributes` marks the development files `export-ignore` as well, so even
the fallback archive stays clean.

## Undoing one

Deleting a release and its tag returns merchants to the previous release — or,
if it was the only one, to the head of `main`. Nobody is downgraded
automatically; WordPress only ever offers an update when the available version
is higher than the installed one. To pull a bad release back, publish a higher
version with the fix.

[puc]: https://github.com/YahnisElsts/plugin-update-checker
