---
paths:
  - 'resources/js/**'
---

# Js

## Confirmation dialogs mark their confirm button with data-dialog-autofocus
Radix focuses the first tabbable element when a dialog opens. In a confirmation dialog that is the Cancel button, so pressing Enter cancelled the very thing the user was being asked to confirm.

DialogContent (components/ui/dialog.tsx) now overrides onOpenAutoFocus: if the content contains an element with `data-dialog-autofocus`, focus goes there instead. Escape still cancels, and a caller passing its own onOpenAutoFocus and calling preventDefault keeps full control.

Any new "are you sure?" dialog must put `data-dialog-autofocus` on its confirming button. Dialogs that contain a form field need nothing — Radix focuses the field and Enter submits.

## Never remove a node from a blur handler inside a dialog
Radix's FocusScope runs a MutationObserver over the dialog. Its handler is exactly:

    if (document.activeElement !== document.body) return;
    for (const mutation of mutations) {
        if (mutation.removedNodes.length > 0) focus(container);
    }

So it fires on one narrow condition: a node is **removed** while nothing holds the focus. A blur handler is the one place that is reliably true, because focus is in transit. The trap then takes the focus, and the click that started it is swallowed whole — it never reaches the field or the button it was aimed at.

This bit TagInput (components/transactions/tag-input.tsx), which closes its suggestion list and commits the typed tag when the field is left. Clicking Notes with the list open did nothing and needed a second click; clicking Save did nothing at all.

Two fixes that look right and are not: deferring the update with setTimeout still leaves the click swallowed and makes a Save click read the form before the commit lands; restoring `event.relatedTarget` puts the focus back but cannot replay the lost click.

Do the work on **mouseup** instead, from a document listener, closing over a ref to the component's own element:

    document.addEventListener('mouseup', handleMouseUp, true)

By then the focus has settled and the click's target is already fixed, so the field may even change height and the click still lands where it was aimed. React flushes between mouseup and click, so a form submitted by that same click sees the change. Do not use mousedown: the target is not fixed yet, and a field that grows moves the button out from under the release. Leaving by keyboard is handled on the Tab keydown, where the field still holds the focus.

Attribute changes are safe — the observer is `{ childList: true, subtree: true }` — as is adding nodes. Only removal matters.

The "outside the component" test has to be measured against an element wrapping the field **and** anything it pops up. Measured against the field alone, a click on an option in the popup counts as outside: the list closes before the option's own click can land, so picking a suggestion silently added the text typed so far instead.

## Every navigation must fetch fresh data
This app shows money, so a page must never be rebuilt from a cached copy.

Two things cached a page and showed stale data:

1. `<Link prefetch>` prefetches on hover and caches the response for 30 seconds. Passing the mouse over "Transactions" on the way to "Rules", applying a rule, then clicking "Transactions" served the copy fetched before the rule ran. All nav links now use `prefetch="click"`: the request starts on mousedown and is reused on mouseup while still in flight, so cacheFor is 0 and nothing is ever cached. One request per click, no duplicate. Never write a bare `prefetch` or a `cacheFor` on a link.

2. Back and forward rebuild the page from the history entry without asking the server. app.tsx sets a flag on `popstate` and calls `router.reload()` on the next `navigate` event, when the restored page is already current. The reload visits the same URL, which Inertia replaces rather than pushes, so the history stack is untouched and no second navigate event fires. Verified: one extra request per back/forward, no loop.
