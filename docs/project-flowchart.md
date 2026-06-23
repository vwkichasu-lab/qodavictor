# QODA Project Flowchart

This flowchart summarizes the main QODA PU system flow from landing page to login, exam creation, live proctoring, student exam writing, grading, and result publishing.

## Main System Flow

```mermaid
flowchart TD
    A["User opens QODA"] --> B["Landing Page"]
    B --> C{"Choose Action"}
    C -->|Login| D["Login Page"]
    C -->|Register| E["Lecturer Registration"]
    E --> F["Create Lecturer Account"]
    F --> D

    D --> G{"Authenticate User"}
    G -->|Invalid login| D1["Show Login Error"]
    D1 --> D
    G -->|Lecturer| L["Lecturer Dashboard"]
    G -->|Student| S["Student Dashboard"]
    G -->|Admin| AD["Admin Dashboard"]

    AD --> AD1["Manage Users"]
    AD --> AD2["View System Activity"]
    AD --> AD3["Manage Sessions"]

    L --> L1["Create or Edit Exam"]
    L1 --> L2["Enter Basic Exam Information"]
    L2 --> L3["Add Questions"]
    L3 --> L4["Set Problem Statement and Marks"]
    L4 --> L5["Choose Programming Language"]
    L5 --> L6["Add Starter Code and Model Solution"]
    L6 --> L7["Add or Generate Test Cases"]
    L7 --> L8{"Validation Ready?"}
    L8 -->|No| L3
    L8 -->|Yes| L9["Save Draft / Publish Exam"]
    L9 --> L10["Exam Available to Students"]

    S --> S1["View Available Exams"]
    S1 --> S2["Open Exam"]
    S2 --> S3["Exam Landing / Access Check"]
    S3 --> S4{"Allowed to Start?"}
    S4 -->|No| S5["Show Access / Schedule Message"]
    S4 -->|Yes| S6["Start Exam Interface"]

    S6 --> S7["Start Screen Sharing"]
    S7 --> S8{"Screen Sharing Active?"}
    S8 -->|No| S9["Block / Warn Student"]
    S9 --> S7
    S8 -->|Yes| S10["Load Questions and Code Editor"]

    S10 --> S11["Student Writes Code"]
    S11 --> S12["Run Code / Test Cases"]
    S12 --> S13["Autosave Answers"]
    S13 --> S14{"Exam Finished?"}
    S14 -->|No| S11
    S14 -->|Yes| S15["Submit Exam"]

    L10 --> M["Live Proctoring Dashboard"]
    M --> M1["View Students Writing"]
    M --> M2["Watch Live Screen Share"]
    M --> M3["Pause / Resume Exam Timer"]
    M --> M4["Add Time to One Student"]
    M --> M5["Add Time to All Students"]
    M --> M6["Send Message to Student or All"]
    M --> M7["Warn / Lock / Unlock Student"]

    S15 --> G1["Store Submission"]
    G1 --> G2["Auto Grade Test Cases"]
    G2 --> G3["Lecturer Reviews Grades"]
    G3 --> G4["Publish Results"]
    G4 --> S16["Student Views Result"]
```

## Lecturer Exam Creation Flow

```mermaid
flowchart TD
    A["Lecturer Dashboard"] --> B["Create New Exam"]
    B --> C["Basic Exam Information"]
    C --> C1["Course / Semester / Programme"]
    C --> C2["Exam Date and Start Time"]
    C --> C3["Duration and Cut-Off Time"]

    C1 --> D["Question Builder"]
    C2 --> D
    C3 --> D

    D --> E["Question Title"]
    E --> F["Problem Statement and Marks"]
    F --> G["Programming Language"]
    G --> H["Starter Code Editor"]
    H --> I["Model Solution Editor"]
    I --> J["Test Cases"]
    J --> K["Marking Scheme"]
    K --> L{"Required Checks Complete?"}

    L -->|No| M["Show Validation Panel"]
    M --> D
    L -->|Yes| N["Save Draft"]
    N --> O{"Publish Exam?"}
    O -->|No| P["Continue Editing Later"]
    O -->|Yes| Q["Publish Exam"]
    Q --> R["Students Can Access Exam"]
```

## Student Exam Flow

```mermaid
flowchart TD
    A["Student Login"] --> B["Student Dashboard"]
    B --> C["Available Exams"]
    C --> D["Open Exam"]
    D --> E{"Exam Available Now?"}

    E -->|No| F["Show Waiting / Access Message"]
    E -->|Yes| G["Exam Landing Page"]
    G --> H["Start Exam"]
    H --> I["Request Screen Sharing"]

    I --> J{"Permission Granted?"}
    J -->|No| K["Show Screen Sharing Required Message"]
    K --> I
    J -->|Yes| L["Enter Exam Interface"]

    L --> M["Load Questions"]
    M --> N["Open Code Editor"]
    N --> O["Write Code"]
    O --> P["Run Code"]
    P --> Q["View Output / Test Result"]
    Q --> R["Autosave Answer"]
    R --> S{"More Questions?"}
    S -->|Yes| M
    S -->|No| T["Submit Exam"]
    T --> U["Submission Stored"]
    U --> V["Await Grade / Result"]
```

## Live Proctoring and Exam Control Flow

```mermaid
flowchart TD
    A["Lecturer Opens Proctoring Page"] --> B["Realtime Socket Connection"]
    B --> C["Load Active Exam Sessions"]
    C --> D["Display 2x2 Student Screen Grid"]

    D --> E["Student Screen Sharing Status"]
    E -->|Sharing| F["Show Live Screen Preview"]
    E -->|Not Sharing| G["Show Not Sharing Warning"]

    D --> H["Lecturer Controls"]
    H --> H1["Pause Exam"]
    H --> H2["Resume Exam"]
    H --> H3["Add Time to Student"]
    H --> H4["Add Time to All Students"]
    H --> H5["Send Message"]
    H --> H6["Warn / Lock / Unlock"]

    H1 --> I["Student Exam Interface Locked"]
    H2 --> J["Student Exam Interface Unlocked"]
    H3 --> K["Student Timer Increases"]
    H4 --> L["All Student Timers Increase"]
    H5 --> M["Student Receives Read-Only Message"]
    H6 --> N["Violation / Evidence Workflow"]
```

## Backend Processing Flow

```mermaid
flowchart TD
    A["Frontend Request"] --> B{"Request Type"}

    B -->|Login / Auth| C["Auth Controller"]
    B -->|Exam Data| D["Exam Controller"]
    B -->|Code Run| E["Code Runner"]
    B -->|Grade Save| F["Grade Storage"]
    B -->|Realtime Event| G["Socket.IO Server"]
    B -->|Migration| H["Migration Runner"]

    C --> DB["MySQL Database"]
    D --> DB
    F --> DB
    H --> DB

    E --> E1["Create Runtime Job"]
    E1 --> E2["Execute Code Safely"]
    E2 --> E3["Return Output / Error"]

    G --> G1["Exam Monitoring Room"]
    G --> G2["Student Screen Share Events"]
    G --> G3["Timer / Message / Lock Events"]
    G1 --> DB
    G2 --> DB
    G3 --> DB
```
