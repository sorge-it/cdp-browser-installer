# The social preview of the GitHub repository: the name and one refusal, 1280 × 640.
# Run through `make -C demo png`; https://github.com/charmbracelet/vhs

Set Shell bash
Set Width 1280
Set Height 640
Set FontSize 24
Set Theme "Catppuccin Mocha"
Set Padding 28
Set CursorBlink false

Hide
Type "export PS1='$ ' && php social.php && sleep 120"
Enter
# The download took 92 to 98 s on 2026-10-31 under load; wait for the last line, not for a fixed
# time. A failed social.php prints no last line, and VHS stops after 300 s.
Wait+Screen@300s /chrome-mink-driver\./
Show
Sleep 500ms

Screenshot ../output/social.png
