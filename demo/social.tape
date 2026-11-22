# The social preview of the GitHub repository: the name, the pair in one version, three benefits,
# 1280 × 640. Run through `make -C demo png`; https://github.com/charmbracelet/vhs

Set Shell bash
Set Width 1280
Set Height 640
Set FontSize 26
Set LineHeight 1.15
Set Theme "Catppuccin Mocha"
# The frame of demo.tape: a window on a violet ground.
Set WindowBar Colorful
Set BorderRadius 14
Set Margin 30
Set MarginFill "#8839EF"
Set Padding 30
Set CursorBlink false

Hide
Type "php social.php && sleep 120"
Enter
# The download took 92 to 98 s on 2026-10-31 under load; wait for the last line, not for a fixed
# time. A failed social.php prints no last line, and VHS stops after 300 s.
Wait+Screen@300s /chrome-php and Mink/
Show
Sleep 500ms

Screenshot ../output/social.png
# Without a command after it, VHS may end before it writes the screenshot.
Sleep 500ms
