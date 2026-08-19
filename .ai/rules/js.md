---
paths:
  - 'resources/js/**'
---

# Js

## Confirmation dialogs mark their confirm button with data-dialog-autofocus
Radix focuses the first tabbable element when a dialog opens. In a confirmation dialog that is the Cancel button, so pressing Enter cancelled the very thing the user was being asked to confirm.

DialogContent (components/ui/dialog.tsx) now overrides onOpenAutoFocus: if the content contains an element with `data-dialog-autofocus`, focus goes there instead. Escape still cancels, and a caller passing its own onOpenAutoFocus and calling preventDefault keeps full control.

Any new "are you sure?" dialog must put `data-dialog-autofocus` on its confirming button. Dialogs that contain a form field need nothing — Radix focuses the field and Enter submits.
