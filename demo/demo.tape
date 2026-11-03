# The animated demo in the README: `install` from the pin file, the version of each binary of the
# pair, and an archive with one byte added, which `install` refuses. `make -C demo install` wrote the pin file before.
# Run through `make -C demo gif`; https://github.com/charmbracelet/vhs

Output ../output/demo.gif

Set Shell bash
Set Width 1280
Set Height 480
Set FontSize 16
Set Theme "Catppuccin Mocha"
Set Padding 24
Set Framerate 12
Set TypingSpeed 15ms
Set CursorBlink false
# Each command waits for the prompt, so a slow disk does not type into the output.
Set WaitPattern /^\$\s*$/
Set WaitTimeout 300s

# The first frame shows the pin file: GitHub shows only that frame to a reader who reduces motion.
Hide
Type "export PS1='$ ' && rm -rf ~/.cache/cdp-browser && clear"
Enter
Wait
Type "grep -e version -e chrome -e linux-arm64 .chrome-version"
Enter
Wait
Show
Sleep 2s

Type "vendor/bin/cdp-browser install"
Enter
# The download and the unpack take a minute or more: the GIF leaves the wait out.
Hide
Wait
Show
Sleep 1s
Type '"$(vendor/bin/cdp-browser path chrome-headless-shell)" --version'
Enter
Wait
Sleep 1s
Type '"$(vendor/bin/cdp-browser path chromedriver)" --version'
Enter
Wait
Sleep 2s

Type "clear"
Enter
Wait
Type "# The archive as a CI cache restores it, with one byte added"
Enter
Wait
Type "dir=~/.cache/cdp-browser/157.0.8103.38/linux-arm64"
Enter
Wait
Type "rm -r $dir/*/ $dir/.sha256-pair"
Enter
Wait
Type "printf x >> $dir/chrome-headless-shell-linux-arm64.zip"
Enter
Wait
Sleep 500ms
Type "vendor/bin/cdp-browser install; ls $dir"
Enter
Wait
Sleep 6s
