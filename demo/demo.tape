# The animated demo in the README: `install` from the pin file, both binaries in one version, then
# an archive of a cache with one byte added: `install` refuses it, the next one downloads it again.
# Run through `make -C demo gif`, which writes the pin file first.

Output ../output/demo.gif

Set Shell bash
# 90 columns: with the cache in /ci/cdp-browser, the refusal wraps before `.zip` and between words,
# never in a hash or a command. app/preflight.php checks it.
Set Width 1112
Set Height 600
Set FontSize 18
Set Theme "Catppuccin Mocha"
# A window on a violet ground, as in social.tape.
Set WindowBar Colorful
Set BorderRadius 12
Set Margin 24
Set MarginFill "#8839EF"
Set Padding 22
Set Framerate 12
Set TypingSpeed 30ms
Set CursorBlink false
# Each command waits for the prompt, so a slow disk does not type into the output.
Set WaitPattern /❯\s*$/
Set WaitTimeout 300s

# The prompt: the directory in blue and ❯ in green after exit code 0, both in red after any other.
Hide
Type "export PS1='\[\e[1;3$(($? ? 1 : 4))m\]\W \[\e[1;3$(($? ? 1 : 2))m\]\342\235\257\[\e[0m\] ' && rm -rf /ci/cdp-browser && clear"
Enter
Wait

# The first frame shows the whole first part: GitHub shows only that frame to a reader who reduces
# motion. The GIF leaves out the wait for the download.
Type "clear && printf '\e[1;30;45m 1/2 \e[0m \e[1;35mInstall the pair from the pin file: both binaries report one version\e[0m\n\n'"
Enter
Wait
Type "export CDP_BROWSER_PATH=/ci/cdp-browser"
Enter
Wait
Type "vendor/bin/cdp-browser install"
Enter
Wait
Type '"$(vendor/bin/cdp-browser path chrome-headless-shell)" --version'
Enter
Wait
Type '"$(vendor/bin/cdp-browser path chromedriver)" --version'
Enter
Wait
Show
Sleep 6s

Hide
Type "clear && printf '\e[1;30;43m 2/2 \e[0m \e[1;33mOne byte added to a cached archive: install refuses it, the next one downloads it\e[0m\n\n'"
Enter
Wait
Show
Sleep 1s
Type "dir=/ci/cdp-browser/158.0.8127.36/linux-arm64"
Enter
Wait
Type "rm -r $dir/*/ $dir/.sha256-pair"
Enter
Wait
Type "printf x >> $dir/chrome-headless-shell-linux-arm64.zip"
Enter
Wait
Sleep 500ms
Type "vendor/bin/cdp-browser install"
Enter
Wait
Sleep 5s
Type "vendor/bin/cdp-browser install"
Enter
Hide
Wait
Show
Sleep 6s
