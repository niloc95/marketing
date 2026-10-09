# How the Partner Program works

A 12-page internal A4 guide to the Partner Program: the model, the rules, a
worked example (Partner A bringing Customer A and Customer B, month by month),
every edge case, and the decisions to make before launch.

Written against `app/Services/PartnerService.php` and `Config\Partners`. When
a rule or setting changes there, change `content.py`. Partner A, Customer A
and Customer B are invented. Internal only: do not publish it to `public/`.

```
content.py               all copy
build_partner_guide.py   renders it with ../playbook/build_playbook.py
build/                   output, gitignored
```

```sh
../playbook/.venv/bin/python build_partner_guide.py
```
