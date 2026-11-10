# Security Policy

## Supported Versions

Security fixes are provided for the latest stable release of SupportDesk.
Earlier releases and development builds are not supported for production use.

Keep SupportDesk, its dependencies, and the underlying hosting environment
up to date.

## Reporting a Vulnerability

Report suspected vulnerabilities privately through
[GitHub private vulnerability reporting](https://github.com/Szeenaaan/Atlazddesk/security/advisories/new).

Do not report vulnerabilities through public issues, pull requests,
or discussions.

Include the following information where available:

- The affected version or commit.
- The affected feature or endpoint.
- Any access or permissions required to reproduce the issue.
- Clear reproduction steps and a minimal proof of concept.
- The expected and observed behavior.
- The potential security impact.
- A suggested fix, if available.

Use synthetic test data. Remove passwords, API keys, authentication
tokens, personal information, and customer data from reports,
screenshots, and logs.

If sensitive data was exposed, describe its type and scope without
including the data itself.

## Responsible Testing

Test only systems you own or have explicit permission to assess.
Use an isolated test environment whenever possible.

During testing:

- Use accounts and data created for the assessment.
- Avoid disrupting services or degrading availability.
- Do not access, modify, download, or delete other users' data.
- Do not attempt social engineering, phishing, or physical attacks.
- Stop testing if you encounter sensitive data or unexpected service
  disruption, and report the finding privately.

Public availability of an installation does not grant permission
to test it.

## Review and Remediation

We assess reports for reproducibility, impact, and severity.
We may request additional information through the private report.

Confirmed vulnerabilities are prioritized according to their risk.
Fixes may include application changes, dependency updates, or
deployment guidance.

Response and resolution times depend on the severity and complexity
of the issue. Follow up through the original private report.

## Coordinated Disclosure

Please coordinate publication of vulnerability details with the
maintainers so affected users have an opportunity to update.

Security advisories will provide affected versions, available fixes,
and mitigation guidance where applicable. Reporter attribution will
be included with the reporter's consent.
