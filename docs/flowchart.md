# Flowchart: a client's booking path

From opening the site to a confirmed booking. Renders on GitHub.

```mermaid
flowchart TD
    A([Client opens the site]) --> B[Pick branch and treatment]
    B --> C{Always sees the<br/>same therapist?}
    C -->|Yes| D[Pick that therapist]
    C -->|No| E[Anyone available]
    D --> F[Pick a date]
    E --> F

    F --> G[["GET /api/availability"]]

    G --> H[/"Sweep expired holds<br/>(no cron - runs on this request)"/]
    H --> I{Branch open<br/>that weekday?}
    I -->|No| J[No times shown]
    I -->|Yes| K["For each 30-min start from opening:<br/>• treatment must end by closing<br/>• not in the past"]

    K --> L{"Qualified therapist free?<br/>(treatment + her own buffer)"}
    L -->|No| M[Skip this time]
    L -->|Yes| N{"Equipped room free?<br/>(treatment + 15 min cleanup)"}
    N -->|No| M
    N -->|Yes| O[Offer this time]

    O --> P[Client picks a time]
    J --> P2([Client tries another day]) --> F
    P --> Q[Enter name and phone]

    Q --> QA[/"Normalise the number to E.164<br/>085-555-5555 → +66855555555"/]
    QA --> QB{"Number already<br/>on file?"}
    QB -->|Yes| QC["Same client row —<br/>her membership follows her"]
    QB -->|No| QD[New client row]
    QC --> R{Laser treatment?}
    QD --> R
    R -->|Yes| S["Consent: ID number + date of birth<br/>(encrypted at rest)"]
    R -->|No| T[["POST /api/bookings"]]
    S --> T

    T --> U[/"BEGIN TRANSACTION"/]
    U --> V["Re-check availability<br/>(fresh read)"]
    V --> W[INSERT booking]

    W --> X{"Database exclusion<br/>constraints accept it?"}

    X -->|"No — room or therapist<br/>taken mid-flight"| Y{Another room or<br/>therapist free?}
    Y -->|Yes| V
    Y -->|"No (after 3 tries)"| Z["409 — that time just went"]
    Z --> F

    X -->|"No — client already<br/>booked at that time"| Z2["422 — you already<br/>have a booking then"]

    X -->|Yes| AA{Is the client<br/>a member?}

    AA -->|Yes| AB["status = confirmed<br/>no deposit"]
    AA -->|No| AC["status = pending_payment<br/>slot held for 10 minutes"]

    AB --> AD([Booked — reference issued])

    AC --> AE[["POST /api/bookings/{ref}/deposit<br/>Idempotency-Key header"]]

    AE --> AF{Key already<br/>seen?}
    AF -->|Yes| AG["Replay the first response<br/>— no second charge"]
    AG --> AD

    AF -->|No| AH{Booking already<br/>has a payment?}
    AH -->|Yes| AG
    AH -->|No| AI["Write payment row,<br/>then charge the gateway"]

    AI --> AJ{Charge<br/>succeeded?}
    AJ -->|"No"| AK["Roll back — hold survives,<br/>client can retry"]
    AK --> AE
    AJ -->|Yes| AL["status = confirmed<br/>hold cleared"]
    AL --> AD

    AC -.->|"10 minutes pass,<br/>no payment"| AM["Swept to 'expired'<br/>on the next request"]
    AM -.-> AN([Slot free again])

    AD --> AO["Free cancellation<br/>until 24h before"]

    style X fill:#ffe9e9,stroke:#c0392b,stroke-width:2px
    style AF fill:#ffe9e9,stroke:#c0392b,stroke-width:2px
    style AH fill:#ffe9e9,stroke:#c0392b,stroke-width:2px
    style H fill:#fff6e0,stroke:#b9770e
    style AM fill:#fff6e0,stroke:#b9770e
    style AD fill:#e9f7ef,stroke:#186a3b,stroke-width:2px
    style AN fill:#e9f7ef,stroke:#186a3b
```

**Red** are the points where correctness is enforced under concurrency: the
database refusing an overlapping booking, and the two idempotency checks that
stop a second charge.

**Amber** are the lazy hold sweeps that stand in for the scheduled job the
hosting cannot run.

---

## The same path, told as a story

Nok wants laser hair removal at the Thonglor branch on a Saturday.

She picks the treatment and the branch, and says she only sees Pim. The site
asks the server what is free. Before answering, the server quietly releases any
abandoned holds - there is no background job, so this happens now, on her
request. It then walks Saturday in half-hour steps, keeping only times where Pim
is free *and* the laser suite is free for the treatment plus its fifteen minutes
of cleaning.

She picks 3pm. She types her number with dashes, the way she always writes it.
It is normalised before it is matched, so she is recognised as the same Nok who
came in March rather than becoming a second record with the same name - which
matters, because whether a deposit is due is decided by the record she lands on.

Because it is a laser treatment, she signs the consent and gives her ID number,
which is encrypted before it is stored.

Her booking goes in. At the same moment, a receptionist at the desk is entering
a phone booking for the same laser suite at 3pm. Both were looking at a free
slot. The database accepts exactly one of them. If Nok loses, she is told
immediately and shown other times - she is never given a confirmation for a slot
that is not hers. Last Christmas, both would have been written down.

Nok is not a member, so the slot is held for ten minutes while she pays the 300
deposit. Her phone drops the connection and she taps pay again. The second
request carries the same key as the first, so the server returns the original
result instead of charging her twice.

She gets a reference. She can cancel free until 3pm the day before.

Had she abandoned the payment, her hold would have lapsed after ten minutes and
been released the next time anyone asked about that Saturday.
