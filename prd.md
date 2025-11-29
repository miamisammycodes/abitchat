# Product Requirements Document (PRD)
# AI-Powered WordPress Chatbot SaaS

**Document Version:** 1.0  
**Last Updated:** November 2025  
**Status:** Draft  
**Author:** [Your Name]  
**Stakeholders:** [Product, Engineering, Sales]

---

## 1. Executive Summary

This document outlines the requirements for a standalone SaaS AI chatbot delivered as a WordPress plugin. The product serves two primary functions: automated customer support and intelligent lead qualification/sales assistance. Built on Laravel 12+ with LLM-powered conversations, the chatbot enables B2B businesses to automate customer interactions, capture qualified leads, and reduce support burden—all without requiring technical expertise.

**Target Launch:** [TBD]  
**Primary Market:** Existing website clients (Phase 1), broader WordPress ecosystem (Phase 2)  
**Competitive Positioning:** Local alternative to Nomind Bhutan with WordPress-native integration and flexible pricing

---

## 2. Problem Statement

### Customer Pain Points

B2B businesses with WordPress websites face several challenges:

1. **24/7 Availability Gap:** Customer inquiries arrive outside business hours with no immediate response mechanism
2. **Lead Leakage:** Website visitors leave without engagement; no systematic capture of contact information or intent signals
3. **Support Overload:** Repetitive questions consume staff time that could be spent on complex issues or sales activities
4. **Technical Barriers:** Existing chatbot solutions require technical expertise, complex integrations, or expensive enterprise contracts
5. **Fragmented Tools:** Businesses use separate tools for support, lead capture, and communication—creating data silos

### Business Opportunity

WordPress powers approximately 43% of all websites globally. A WordPress-native AI chatbot with simple installation, no-code configuration, and affordable tiered pricing addresses an underserved segment of the market—particularly in emerging markets where enterprise solutions are cost-prohibitive.

---

## 3. Product Vision & Goals

### Vision Statement

Empower B2B businesses to deliver intelligent, always-on customer experiences through an AI chatbot that installs in minutes, learns from their content, and turns website visitors into qualified leads—without requiring technical expertise or enterprise budgets.

### Product Goals

| Goal | Success Metric | Target |
|------|----------------|--------|
| Easy Adoption | Time from plugin install to live chatbot | < 15 minutes |
| Support Deflection | % of conversations resolved without human escalation | > 60% |
| Lead Capture | % of conversations that capture contact info | > 40% |
| Customer Retention | Monthly churn rate | < 5% |
| Revenue Growth | MRR growth (post-launch) | 15% month-over-month |

### Non-Goals (V1)

- Live chat/human handoff (planned for V2)
- Native mobile apps
- Multi-language support (English only in V1)
- CRM integrations (planned for V2)
- Voice/phone channel support
- E-commerce transaction handling

---

## 4. Target Users

### Primary Personas

**Persona 1: Small Business Owner (Decision Maker)**
- Owns or operates a B2B service business (consulting, agency, professional services)
- Has a WordPress website but limited technical skills
- Wants to capture more leads and reduce time spent on repetitive inquiries
- Budget-conscious; needs clear ROI justification
- Values simplicity over feature depth

**Persona 2: Marketing Manager (Day-to-Day User)**
- Responsible for website performance and lead generation
- Comfortable with WordPress admin but not a developer
- Needs to configure chatbot messaging, review leads, and report on performance
- Wants customization without complexity

**Persona 3: Support/Operations Staff (End User)**
- Receives escalated conversations via email
- Reviews conversation history to understand customer context
- Needs clear, actionable lead information

### User Roles & Permissions

| Role | Permissions |
|------|-------------|
| Owner/Admin | Full access: billing, team management, all settings, all data |
| Manager | Configure chatbot, view all conversations, manage knowledge base, view analytics |
| Agent | View assigned conversations, export leads, receive escalation emails |

---

## 5. Core Features & Requirements

### 5.1 WordPress Plugin & Widget

**Description:** A lightweight WordPress plugin that embeds an AI chatbot widget on customer websites.

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| WP-01 | One-click installation from WordPress plugin repository | P0 |
| WP-02 | Activation via API key (generated from SaaS dashboard) | P0 |
| WP-03 | Widget renders on all pages by default; configurable page targeting | P1 |
| WP-04 | Asynchronous loading (no impact on page load speed) | P0 |
| WP-05 | Mobile-responsive widget (bottom-right default, configurable) | P0 |
| WP-06 | Customizable appearance: colors, position, avatar, welcome message | P1 |
| WP-07 | Business hours configuration with offline message behavior | P2 |
| WP-08 | Compatible with PHP 8.1+ and WordPress 6.0+ | P0 |
| WP-09 | Compatible with major page builders (Elementor, Divi, Gutenberg) | P1 |
| WP-10 | GDPR cookie consent integration hooks | P1 |

**Widget Customization Options:**

- Primary color / accent color
- Widget position (bottom-right, bottom-left)
- Custom avatar/logo upload
- Welcome message text
- Pre-chat form fields (optional)
- Launcher icon style
- Chat bubble style

### 5.2 Knowledge Base & Training

**Description:** System for customers to provide content that trains the chatbot to answer questions accurately.

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| KB-01 | Document upload (PDF, DOCX, TXT) with automatic text extraction | P0 |
| KB-02 | Manual FAQ entry (question-answer pairs) | P0 |
| KB-03 | Website URL crawler (input URL, crawl and index content) | P1 |
| KB-04 | Copy/paste text content directly | P0 |
| KB-05 | Content chunking and vector embedding (SQLite-vec) | P0 |
| KB-06 | View, edit, and delete knowledge base entries | P0 |
| KB-07 | Re-sync/re-crawl website content on demand | P1 |
| KB-08 | Content source labeling (identify which source answered a query) | P2 |
| KB-09 | Maximum file size: 10MB per document | P0 |
| KB-10 | Supported formats: PDF, DOCX, TXT, MD, HTML | P1 |

**Knowledge Base Limits by Plan:**

| Plan | Documents | FAQ Pairs | Website Pages |
|------|-----------|-----------|---------------|
| Starter | 10 | 50 | 20 |
| Business | 50 | 200 | 100 |
| Enterprise | Unlimited | Unlimited | Unlimited |

### 5.3 AI Conversation Engine

**Description:** LLM-powered conversation system that handles customer support and lead qualification.

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| AI-01 | RAG-based responses using customer's knowledge base | P0 |
| AI-02 | Contextual conversation memory within session | P0 |
| AI-03 | Graceful handling of out-of-scope questions | P0 |
| AI-04 | Configurable AI personality/tone (professional, friendly, casual) | P1 |
| AI-05 | Automatic language detection and response matching | P2 |
| AI-06 | Streaming responses for better UX | P1 |
| AI-07 | Fallback behavior when confidence is low | P0 |
| AI-08 | Token usage tracking per conversation | P0 |
| AI-09 | Rate limiting per tenant (based on plan) | P0 |
| AI-10 | Response latency < 3 seconds (p95) | P0 |

**LLM Configuration (via Prism):**

- Development/Testing: Ollama with Gemma2 2B or Gemma3 4B (local inference)
- Production: Groq with Llama 3.1 8B (cloud API)
- Fallback: Configurable secondary provider (easy switch via Prism)
- Streaming: Enabled via Prism's `asEventStreamResponse()` for real-time responses

> **Note:** Prism is an API abstraction layer, not an LLM runtime. See Section 7.3 for detailed architecture explaining how Prism communicates with LLM providers.

**Conversation Modes:**

1. **Support Mode:** Answers questions using knowledge base; aims to resolve inquiry
2. **Sales Mode:** Engages visitors, qualifies interest, captures lead information
3. **Hybrid Mode (Default):** Dynamically switches based on conversation signals

### 5.4 Lead Capture & Qualification

**Description:** Intelligent lead capture integrated naturally into conversations.

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| LC-01 | Capture visitor name during conversation | P0 |
| LC-02 | Capture email address (with validation) | P0 |
| LC-03 | Capture phone number (with basic validation) | P0 |
| LC-04 | Lead scoring based on conversation keywords/signals | P1 |
| LC-05 | Configurable qualifying questions | P1 |
| LC-06 | Lead list with search, filter, and export (CSV) | P0 |
| LC-07 | Lead detail view with full conversation history | P0 |
| LC-08 | Lead status management (New, Contacted, Qualified, Converted, Lost) | P1 |
| LC-09 | Duplicate lead detection (by email) | P1 |
| LC-10 | Custom field capture (company name, budget, etc.) | P2 |

**Lead Scoring Model:**

| Signal | Score Impact |
|--------|--------------|
| Provided email | +20 |
| Provided phone | +15 |
| Provided name | +10 |
| Asked about pricing | +25 |
| Asked about demo/trial | +30 |
| Multiple sessions | +10 |
| High engagement (>5 messages) | +15 |
| Mentioned competitor | +20 |
| Mentioned timeline/urgency | +25 |
| Negative sentiment detected | -10 |

**Score Ranges:**

- 0-30: Cold
- 31-60: Warm
- 61-100: Hot

### 5.5 Escalation & Notifications

**Description:** System for handling conversations that require human intervention.

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| ES-01 | Email notification to assigned team member on escalation trigger | P0 |
| ES-02 | Email includes: customer info, full conversation transcript, lead score | P0 |
| ES-03 | Configurable escalation triggers (keywords, sentiment, explicit request) | P1 |
| ES-04 | Escalation email template customization | P2 |
| ES-05 | Multiple notification recipients (by role or specific users) | P1 |
| ES-06 | Escalation queue view in dashboard | P1 |
| ES-07 | Mark escalation as resolved | P1 |
| ES-08 | Auto-escalate after X unanswered questions (configurable) | P1 |

**Default Escalation Triggers:**

- Visitor explicitly requests human assistance
- Visitor expresses frustration (sentiment analysis)
- Bot responds with low confidence 3+ times
- Conversation contains configured keywords (e.g., "complaint," "refund," "urgent")

### 5.6 Admin Dashboard (Platform Administration)

**Description:** Internal platform dashboard for your team to manage the entire SaaS—clients, billing, system health, and platform-wide analytics. This is separate from the Client Dashboard.

#### 5.6.1 Admin Dashboard Home

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| ADH-01 | Platform-wide key stats display (total clients, active clients, MRR, total conversations today) | P0 |
| ADH-02 | Total LLM token usage (platform-wide, current billing period) | P0 |
| ADH-03 | Client token usage list (sortable table showing each client's token consumption) | P0 |
| ADH-04 | Recent activity feed (new signups, plan upgrades, escalations, errors) | P0 |
| ADH-05 | Alerts panel (quota warnings, failed jobs, system issues) | P0 |
| ADH-06 | Quick actions (impersonate client, adjust quota, broadcast message) | P1 |

**Dashboard Home Widgets:**

- **Platform Stats Card:** Total clients, active clients (last 7 days), new signups (this month), MRR
- **Token Usage Card:** Total tokens consumed (today/this week/this month), token cost estimate, trend chart
- **Client Token Leaderboard:** Top 10 clients by token usage with usage bars
- **Revenue Card:** MRR, ARR projection, average revenue per client
- **Alert Badge:** Count of active alerts requiring attention

#### 5.6.2 Client Management

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| ACM-01 | Client list with search, filter (by plan, status, signup date) | P0 |
| ACM-02 | Client detail view (profile, plan, usage stats, team members) | P0 |
| ACM-03 | Impersonate/login-as-client functionality for troubleshooting | P0 |
| ACM-04 | Manually adjust client token quota (override plan limits) | P0 |
| ACM-05 | Manually change client plan without Stripe (comp accounts, special deals) | P0 |
| ACM-06 | Suspend/disable client account | P0 |
| ACM-07 | Reactivate suspended account | P0 |
| ACM-08 | View client's full conversation history | P1 |
| ACM-09 | View client's knowledge base content | P1 |
| ACM-10 | Add internal notes to client account | P1 |
| ACM-11 | Export client list (CSV) | P2 |

**Client Detail View Sections:**

- **Overview:** Company name, contact email, signup date, plan, status
- **Usage:** Token usage (current period, historical chart), conversations count, leads captured
- **Billing:** Current plan, billing status, payment history, manual adjustments
- **Team:** List of team members with roles
- **Activity Log:** Recent actions (logins, configuration changes, escalations)
- **Admin Actions:** Impersonate, adjust quota, change plan, suspend

#### 5.6.3 Platform Analytics

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| APA-01 | Total clients (all-time, active, churned) with trend charts | P0 |
| APA-02 | Total conversations across all clients (daily/weekly/monthly) | P0 |
| APA-03 | Platform-wide token consumption with cost tracking | P0 |
| APA-04 | Revenue metrics (MRR, ARR, ARPU, churn rate) | P0 |
| APA-05 | Client acquisition funnel (signups → activated → paying → retained) | P1 |
| APA-06 | Plan distribution breakdown (pie chart) | P1 |
| APA-07 | Top clients by usage (conversations, tokens, leads) | P1 |
| APA-08 | Geographic distribution of clients | P2 |
| APA-09 | Export analytics reports (CSV, PDF) | P2 |

**Analytics Dashboard Sections:**

- **Business Overview:** MRR trend, client count trend, churn rate
- **Usage Overview:** Total conversations, total tokens, average per client
- **Client Health:** Activation rate, engagement distribution, at-risk clients (low usage)
- **Token Economics:** Token consumption vs. cost, margin analysis by plan tier

#### 5.6.4 Token Usage Monitoring

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| ATU-01 | Real-time platform token usage counter | P0 |
| ATU-02 | Token usage by client (sortable, searchable table) | P0 |
| ATU-03 | Token usage breakdown by conversation for any client | P1 |
| ATU-04 | Identify high-token conversations (outliers) | P1 |
| ATU-05 | Token cost calculator (usage × Groq pricing) | P1 |
| ATU-06 | Historical token usage charts (daily, weekly, monthly) | P1 |
| ATU-07 | Token usage alerts (platform-wide thresholds) | P1 |

**Token Usage Table Columns:**

- Client name
- Plan
- Token quota
- Tokens used (current period)
- Usage percentage (with color coding: green < 70%, yellow 70-90%, red > 90%)
- Conversations count
- Last active

#### 5.6.5 System Health & Monitoring

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| ASH-01 | LLM API status indicator (Groq connection health, latency) | P0 |
| ASH-02 | Queue backlog monitor (pending jobs count, processing rate) | P0 |
| ASH-03 | Error rate tracking (API errors, failed responses) | P0 |
| ASH-04 | Average response latency (p50, p95, p99) | P0 |
| ASH-05 | Failed jobs list with retry capability | P0 |
| ASH-06 | Failed document processing list with error details | P0 |
| ASH-07 | Failed email delivery list | P1 |
| ASH-08 | Database connection health | P1 |
| ASH-09 | Redis/cache health | P1 |
| ASH-10 | Uptime tracking | P2 |

**System Health Dashboard:**

- **Status Cards:** LLM API (green/yellow/red), Queue (healthy/backlogged), Database (connected/issues)
- **Latency Chart:** Response time over last 24 hours
- **Error Log:** Recent errors with stack traces
- **Failed Jobs Table:** Job type, error message, failed at, retry button
- **Processing Failures:** Document uploads, web crawls, email sends

#### 5.6.6 Broadcast & Announcements

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| ABA-01 | Create broadcast message to all clients | P1 |
| ABA-02 | Target broadcast by plan tier or client segment | P1 |
| ABA-03 | Schedule broadcast for future delivery | P2 |
| ABA-04 | Broadcast delivery channels: in-dashboard notification, email | P1 |
| ABA-05 | Broadcast history with delivery stats | P1 |
| ABA-06 | System maintenance banner (display on client dashboards) | P1 |

**Broadcast Types:**

- **Announcement:** New feature, product update
- **Maintenance Notice:** Scheduled downtime, degraded performance
- **Urgent Alert:** Security notice, critical issue
- **Promotional:** Upgrade offer, feedback request

#### 5.6.7 Admin Notification Center

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| ANC-01 | Centralized notification inbox for admin team | P0 |
| ANC-02 | Notification types: client quota alerts, system errors, new signups, escalations | P0 |
| ANC-03 | Mark notifications as read/unread | P0 |
| ANC-04 | Filter notifications by type, date | P1 |
| ANC-05 | Notification bell with unread count in header | P0 |
| ANC-06 | Email notifications for critical alerts (configurable) | P1 |
| ANC-07 | Notification preferences per admin user | P2 |

**Admin Notification Triggers:**

- Client approaching token quota (80%, 95%, 100%)
- Client exceeded quota
- New client signup
- Client plan upgrade/downgrade
- Client account suspended/cancelled
- System error rate spike
- LLM API degradation/outage
- Failed jobs threshold exceeded
- High-value lead captured (across platform)

#### 5.6.8 Admin Activity Logs

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| AAL-01 | Log all admin actions (who did what, when) | P0 |
| AAL-02 | Searchable/filterable activity log | P0 |
| AAL-03 | Actions logged: impersonation, quota changes, plan changes, suspensions, broadcasts | P0 |
| AAL-04 | Export activity logs | P2 |
| AAL-05 | Retention policy (90 days default) | P2 |

**Activity Log Entry Structure:**

- Timestamp
- Admin user
- Action type
- Target (client, system)
- Details (before/after values)
- IP address

#### 5.6.9 Admin Roles & Permissions

| Role | Permissions |
|------|-------------|
| Super Admin | Full access: all features, system settings, admin user management |
| Admin | Client management, analytics, broadcasts, monitoring (no system settings) |
| Support | View clients, impersonate, view logs (no modifications, no billing access) |

---

### 5.7 Client Dashboard

**Description:** Dashboard for subscribed clients (businesses) to configure their chatbot, manage leads, view analytics, and manage their team. Completely separate from Admin Dashboard.

#### 5.7.1 Client Dashboard Home

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CDH-01 | Key stats overview (conversations today, leads this week, resolution rate) | P0 |
| CDH-02 | Token usage display (used/quota with progress bar) | P0 |
| CDH-03 | Token usage breakdown by day (chart) | P1 |
| CDH-04 | Recent conversations list (last 5-10) | P0 |
| CDH-05 | Recent leads list (last 5-10) | P0 |
| CDH-06 | Alerts panel (quota warnings, unanswered questions, setup incomplete) | P0 |
| CDH-07 | Quick actions (test chatbot, add knowledge, view leads) | P1 |
| CDH-08 | Onboarding progress indicator (for new clients) | P0 |

**Dashboard Home Widgets:**

- **Today's Stats:** Conversations, leads captured, messages exchanged
- **Token Usage Meter:** Visual progress bar with used/remaining, projected usage
- **Performance Card:** Resolution rate, average response time, lead capture rate
- **Activity Feed:** Recent conversations with visitor info and status
- **Alerts Card:** Actionable items requiring attention

#### 5.7.2 Conversation Management

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CCM-01 | Conversation list with real-time updates (new conversations appear automatically) | P0 |
| CCM-02 | Filter by: date range, status, lead score, assigned agent | P0 |
| CCM-03 | Search conversations by content, visitor info | P0 |
| CCM-04 | Sort by: date, lead score, message count | P0 |
| CCM-05 | Conversation detail view (full transcript, visitor info, lead score breakdown) | P0 |
| CCM-06 | Archive conversation | P1 |
| CCM-07 | Flag/star conversation for follow-up | P1 |
| CCM-08 | Assign conversation to team member | P1 |
| CCM-09 | Add internal notes to conversation (not visible to visitor) | P1 |
| CCM-10 | Export conversation transcript | P2 |
| CCM-11 | Token usage display per conversation | P1 |

**Conversation List Columns:**

- Visitor identifier (name if captured, or session ID)
- Status (active, ended, escalated)
- Lead score (with color indicator)
- Messages count
- Started at
- Last message at
- Assigned to

**Conversation Detail View:**

- **Header:** Visitor info, lead score, status, assigned agent
- **Transcript:** Full message history with timestamps
- **Sidebar:** Lead info (if captured), score breakdown, visitor metadata (browser, location, referrer)
- **Actions:** Archive, flag, assign, add note, export

#### 5.7.3 Lead Management

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CLM-01 | Lead list with search, filter (status, score range, date, assigned) | P0 |
| CLM-02 | Lead detail view (contact info, score breakdown, conversation link) | P0 |
| CLM-03 | Update lead status (New, Contacted, Qualified, Converted, Lost) | P0 |
| CLM-04 | Assign lead to team member | P1 |
| CLM-05 | Add notes to lead | P1 |
| CLM-06 | Export leads (CSV) with filters | P0 |
| CLM-07 | Bulk actions (update status, assign, export) | P2 |
| CLM-08 | Lead score filtering (Cold, Warm, Hot) | P0 |

**Lead List Columns:**

- Name
- Email
- Phone
- Score (with badge: Cold/Warm/Hot)
- Status
- Captured at
- Assigned to
- Actions

#### 5.7.4 Knowledge Base Management

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CKB-01 | Upload documents (PDF, DOCX, TXT, MD) | P0 |
| CKB-02 | Add FAQ pairs manually | P0 |
| CKB-03 | Crawl website URL | P1 |
| CKB-04 | Paste raw text content | P0 |
| CKB-05 | View all knowledge items with status | P0 |
| CKB-06 | Edit/update knowledge items | P0 |
| CKB-07 | Delete knowledge items | P0 |
| CKB-08 | Processing status indicator (processing, active, failed) | P0 |
| CKB-09 | Failed items list with error details and retry button | P0 |
| CKB-10 | "Test Your Bot" feature (ask questions, see which sources are used) | P1 |
| CKB-11 | Usage quota display (documents/FAQs/pages used vs. limit) | P0 |
| CKB-12 | Re-crawl website on demand | P1 |

**Knowledge Base Interface:**

- **Tabs:** Documents, FAQs, Website Pages, Raw Text
- **Each Item Shows:** Title/source, type, status, created date, actions
- **Failed Items Alert:** Badge showing failure count with link to failed items list
- **Test Bot Panel:** Input field to ask questions, shows response + source attribution

#### 5.7.5 Chatbot Configuration

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CBC-01 | AI personality/tone selection (Professional, Friendly, Casual) | P1 |
| CBC-02 | Custom system prompt (advanced users) | P1 |
| CBC-03 | Response length preference (Concise, Balanced, Detailed) | P1 |
| CBC-04 | Confidence threshold setting (when to say "I don't know") | P1 |
| CBC-05 | Politeness level configuration | P1 |
| CBC-06 | Lead capture behavior (aggressive, moderate, subtle) | P1 |
| CBC-07 | Escalation trigger configuration (keywords, sentiment threshold) | P1 |
| CBC-08 | Welcome message customization | P0 |
| CBC-09 | Fallback message customization (when bot can't answer) | P0 |
| CBC-10 | Business hours configuration | P2 |
| CBC-11 | Offline message customization | P2 |

**Chatbot Settings Sections:**

- **Personality:** Tone, politeness, response style
- **Behavior:** Confidence threshold, lead capture aggressiveness, escalation triggers
- **Messages:** Welcome message, fallback message, offline message
- **Advanced:** Custom system prompt (with warning about impact)

#### 5.7.6 Widget Customization

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CWC-01 | Primary color picker | P0 |
| CWC-02 | Accent color picker | P1 |
| CWC-03 | Widget position (bottom-right, bottom-left) | P0 |
| CWC-04 | Custom avatar/logo upload | P1 |
| CWC-05 | Launcher icon style selection | P1 |
| CWC-06 | Chat header text customization | P1 |
| CWC-07 | "Powered by" badge (removable on Enterprise) | P1 |
| CWC-08 | Custom CSS injection (Enterprise only) | P2 |

#### 5.7.7 Client Analytics

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CAN-01 | Conversations over time (daily/weekly/monthly chart) | P0 |
| CAN-02 | Token usage over time (with projection) | P0 |
| CAN-03 | Lead capture rate trend | P0 |
| CAN-04 | Resolution rate (conversations without escalation) | P0 |
| CAN-05 | Top questions asked (word cloud or list) | P1 |
| CAN-06 | Unanswered questions log (questions bot couldn't answer) | P0 |
| CAN-07 | Peak usage times (heatmap or chart) | P1 |
| CAN-08 | Average conversation length | P1 |
| CAN-09 | Widget engagement rate (widget opens vs. page views) | P1 |
| CAN-10 | Lead score distribution | P1 |
| CAN-11 | Export analytics (CSV) - Business & Enterprise plans | P2 |

**Analytics Dashboard Sections:**

- **Overview:** Key metrics with trends (conversations, leads, resolution rate)
- **Token Usage:** Current usage, historical chart, projected end-of-period usage
- **Engagement:** Widget open rate, conversation start rate, completion rate
- **Content Performance:** Top questions, unanswered questions, knowledge gaps
- **Leads:** Capture rate, score distribution, conversion funnel

#### 5.7.8 Team Management

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CTM-01 | Invite team members by email | P0 |
| CTM-02 | Assign role to team members | P0 |
| CTM-03 | Create custom roles with granular permissions | P1 |
| CTM-04 | Remove team members | P0 |
| CTM-05 | View team member activity | P1 |
| CTM-06 | Transfer ownership | P2 |
| CTM-07 | Seat limit enforcement based on plan | P0 |

**Default Client Roles:**

| Role | Permissions |
|------|-------------|
| Owner | Full access: billing, team, all settings, all data, delete account |
| Manager | Configure chatbot, manage knowledge, view all conversations, view analytics, manage agents |
| Agent | View assigned conversations, update lead status, add notes, receive escalations |

**Custom Role Permissions (Granular):**

- View conversations (all / assigned only)
- Manage conversations (archive, assign, flag)
- View leads (all / assigned only)
- Manage leads (update status, add notes)
- Export data
- Manage knowledge base
- Configure chatbot settings
- Customize widget
- View analytics
- Manage team (invite/remove)
- Access billing

#### 5.7.9 Client Alert Configuration

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CAC-01 | Set custom quota alert threshold (e.g., notify at 80% usage) | P1 |
| CAC-02 | Configure alert channels (in-app, email) | P1 |
| CAC-03 | Alert types: quota warning, daily summary, escalation notification, unanswered question threshold | P1 |
| CAC-04 | Per-user alert preferences | P2 |
| CAC-05 | Quiet hours configuration (no alerts during specific times) | P2 |

**Default Client Alerts:**

- Token quota at 80%, 95%, 100%
- Daily conversation summary (optional)
- Immediate escalation notifications
- Weekly unanswered questions digest

#### 5.7.10 Client Notification Center

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CNC-01 | Notification inbox within dashboard | P0 |
| CNC-02 | Notification types: quota alerts, escalations, system announcements, team activity | P0 |
| CNC-03 | Mark as read/unread | P0 |
| CNC-04 | Notification bell with unread count | P0 |
| CNC-05 | Click notification to navigate to relevant page | P1 |
| CNC-06 | Clear all notifications | P1 |

#### 5.7.11 Billing & Subscription (Client)

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CBS-01 | View current plan and usage | P0 |
| CBS-02 | Upgrade/downgrade plan | P0 |
| CBS-03 | Update payment method | P0 |
| CBS-04 | View billing history and invoices | P0 |
| CBS-05 | Cancel subscription (with feedback collection) | P0 |
| CBS-06 | View next billing date and amount | P0 |
| CBS-07 | Annual/monthly billing toggle | P1 |

#### 5.7.12 Client Branding (Business & Enterprise)

**Functional Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| CBR-01 | Custom logo upload (displays in widget and emails) | P1 |
| CBR-02 | Remove "Powered by" branding (Enterprise only) | P1 |
| CBR-03 | Custom email sender name | P2 |
| CBR-04 | Custom dashboard logo (Enterprise only) | P2 |
| CBR-05 | Custom dashboard colors (Enterprise only) | P2 |
| CBR-06 | Custom domain for dashboard (Enterprise only) | P2 |

---

### 5.8 Dashboard Comparison Summary

| Feature | Admin Dashboard | Client Dashboard |
|---------|-----------------|------------------|
| **Purpose** | Platform management | Business chatbot management |
| **Users** | Your internal team | Subscribed businesses |
| **Scope** | All clients, platform-wide | Single tenant only |
| **Token View** | Platform total + per-client breakdown | Own usage only |
| **Client Management** | Full CRUD, impersonation, suspension | N/A |
| **Billing Control** | Override plans, adjust quotas | Self-service plan changes |
| **System Health** | LLM status, queue monitoring, error tracking | N/A |
| **Broadcasts** | Send to all clients | Receive only |
| **Analytics** | Platform metrics, revenue, all clients | Own conversations, leads, usage |
| **Conversations** | View any client's conversations | Own conversations only |
| **Branding** | N/A | Widget & email customization |

### 5.9 Subscription & Billing

**Description:** Usage-based tiered subscription system.

**Plan Structure:**

| Feature | Starter | Business | Enterprise |
|---------|---------|----------|------------|
| Monthly Price | $29 | $79 | $199 |
| Team Seats | 2 | 5 | 15 |
| Monthly Tokens | 100K | 500K | 2M |
| Conversations/mo | 500 | 2,500 | 10,000 |
| Knowledge Docs | 10 | 50 | Unlimited |
| FAQ Pairs | 50 | 200 | Unlimited |
| Website Pages | 20 | 100 | Unlimited |
| Lead Scoring | Basic | Advanced | Advanced + Custom |
| Analytics | Basic | Full | Full + Export |
| Support | Email | Priority Email | Dedicated |
| Custom Branding | ❌ | ✅ | ✅ |
| Remove "Powered by" | ❌ | ❌ | ✅ |

**Billing Requirements:**

| ID | Requirement | Priority |
|----|-------------|----------|
| BL-01 | Free 14-day trial (no credit card required) | P0 |
| BL-02 | Credit card payment processing (Stripe integration) | P0 |
| BL-03 | Monthly and annual billing (annual = 2 months free) | P1 |
| BL-04 | Usage tracking and quota enforcement | P0 |
| BL-05 | Overage handling (soft limit with warning, hard limit with graceful degradation) | P1 |
| BL-06 | Plan upgrade/downgrade self-service | P0 |
| BL-07 | Invoice generation and history | P1 |
| BL-08 | Cancellation flow with feedback collection | P1 |
| BL-09 | Dunning management (failed payment retry) | P2 |

---

## 6. User Flows

### 6.1 New Customer Onboarding

```
1. Land on marketing website
2. Click "Start Free Trial"
3. Create account (email, password, company name)
4. Email verification
5. Onboarding wizard begins:
   a. Step 1: Install WordPress plugin (download link + instructions)
   b. Step 2: Enter API key in WordPress plugin settings
   c. Step 3: Add knowledge (upload docs OR paste FAQs OR enter website URL)
   d. Step 4: Customize widget (colors, welcome message)
   e. Step 5: Test chatbot (preview mode)
   f. Step 6: Go live (activate on website)
6. Dashboard home with quick stats and next steps
```

### 6.2 Visitor Chatbot Interaction

```
1. Visitor lands on customer's WordPress website
2. Chat widget appears (after configurable delay or immediately)
3. Visitor clicks widget to open chat
4. Welcome message displays
5. Visitor types question
6. System:
   a. Queries vector database for relevant knowledge
   b. Constructs prompt with context
   c. Sends to LLM (Groq/Llama)
   d. Streams response to visitor
7. Conversation continues; system tracks engagement signals
8. If sales signals detected:
   a. Bot naturally asks for contact information
   b. Validates and stores lead data
   c. Continues helpful conversation
9. If escalation triggered:
   a. Bot acknowledges and collects contact info if not already captured
   b. Sends notification email to team
   c. Displays message that team will follow up
10. Conversation ends; data stored for analytics
```

### 6.3 Lead Review & Follow-up

```
1. Team member receives escalation email or checks dashboard
2. Opens lead list, filters by status/score
3. Clicks lead to view details:
   - Contact information
   - Lead score with breakdown
   - Full conversation transcript
   - Timestamp and session info
4. Reviews conversation for context
5. Updates lead status
6. Follows up externally (email/phone)
7. Records outcome in status
```

---

## 7. Technical Architecture

### 7.1 System Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Customer's Website                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  WordPress Plugin (JS Widget)                │   │
│  └─────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ HTTPS/SSE (Laravel Streams)
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         SaaS Backend (Laravel 12+)                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │   Inertia    │  │    Prism     │  │   Laravel    │              │
│  │   (Router)   │  │   (LLM)      │  │   Streams    │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│          │                 │                 │                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │   Vue 3 +    │  │   Queue      │  │   Scheduler  │              │
│  │   Tailwind   │  │   Workers    │  │   (Cron)     │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│          │                 │                 │                      │
│          ▼                 ▼                 ▼                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │              Spatie Multi-Tenancy Layer                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│          │                                                          │
│          ▼                                                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │    MySQL     │  │  SQLite-vec  │  │    Redis     │              │
│  │   (Data)     │  │   (Vectors)  │  │   (Cache)    │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ HTTPS (via Prism)
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        External Services                            │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │  Groq/Ollama │  │    Stripe    │  │    SMTP      │              │
│  │   (LLM)      │  │  (Billing)   │  │   (Email)    │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
└─────────────────────────────────────────────────────────────────────┘
```

### 7.2 Technology Stack

| Component | Technology | Notes |
|-----------|------------|-------|
| Backend Framework | Laravel 12+ | PHP 8.2+ |
| Database | MySQL 8.0+ | Primary data store |
| Vector Database | SQLite-vec | RAG embeddings |
| Cache | Redis | Sessions, rate limiting, queues |
| Multi-tenancy | Spatie Laravel Multitenancy | Tenant isolation |
| Queue | Laravel Queue (Redis driver) | Background jobs |
| AI/LLM Integration | Prism | Unified LLM abstraction layer (API client, not runtime) |
| Response Streaming | Laravel Streams + Prism SSE | Native Server-Sent Events support |
| LLM (Dev) | Ollama + Gemma2 2B | Local inference server (runs on dev machine) |
| LLM (Prod) | Groq + Llama 3.1 8B | Cloud inference API (hosted by Groq) |
| Payments | Stripe | Subscriptions, invoicing |
| Email | SMTP (Mailgun/SES) | Transactional email |
| Frontend Framework | Vue 3 | Composition API, reactive UI |
| Frontend Routing | Inertia.js | SPA-like experience without API |
| CSS Framework | Tailwind CSS | Utility-first styling |
| Frontend (Widget) | Vanilla JS / Preact | Lightweight embed |
| Hosting | [TBD] | VPS or managed platform |

**VILT Stack Benefits:**

- **Vue 3:** Reactive components, Composition API for cleaner code, excellent TypeScript support
- **Inertia.js:** Server-side routing with client-side rendering, no separate API layer needed, shared validation
- **Laravel:** Robust backend, excellent ecosystem, built-in authentication/authorization
- **Tailwind CSS:** Rapid UI development, consistent design system, small production bundle

---

### 7.3 LLM Architecture & Prism Integration

**Important:** Prism is an **API abstraction layer**, not an LLM runtime. It provides a unified Laravel-style interface to communicate with external LLM providers via HTTP requests. The actual model inference happens on external services.

#### How Prism Works

```
┌─────────────────────────────────────────────────────────────────────┐
│                      Your Laravel Application                       │
│                                                                     │
│   $response = Prism::text()                                         │
│       ->using(Provider::Groq, 'llama-3.1-8b-instant')              │
│       ->withPrompt($userMessage)                                    │
│       ->asText();                                                   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ Prism translates to HTTP request
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         Prism (API Client)                          │
│                                                                     │
│   • Unified interface for all providers                             │
│   • Handles authentication, retries, error handling                 │
│   • Tracks token usage                                              │
│   • Manages streaming responses                                     │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ HTTPS Request
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    LLM Provider (Runs the Model)                    │
│                                                                     │
│   Development: Ollama (localhost:11434)                             │
│   └── Runs Gemma2 2B locally on your CPU/GPU                       │
│                                                                     │
│   Production: Groq API (api.groq.com)                               │
│   └── Runs Llama 3.1 8B on Groq's cloud infrastructure             │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ JSON/SSE Response
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      Response Back to Laravel                       │
│                                                                     │
│   $response->text           // Generated text                       │
│   $response->usage          // Token counts for billing             │
│   $response->finishReason   // Why generation stopped               │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

#### Development Environment (Ollama)

Ollama is a **local LLM inference server** that runs on your development machine. You must install and run it separately.

**Setup:**
```bash
# Install Ollama (macOS/Linux)
curl -fsSL https://ollama.com/install.sh | sh

# Pull the model (one-time download ~1.5GB for Gemma2 2B)
ollama pull gemma2:2b

# Ollama runs as a service on localhost:11434
ollama serve
```

**Laravel Configuration (.env):**
```env
OLLAMA_URL=http://localhost:11434
```

**Usage:**
```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Ollama, 'gemma2:2b')
    ->withSystemPrompt('You are a helpful customer support assistant.')
    ->withPrompt($userMessage)
    ->withClientOptions(['timeout' => 60]) // Local models may need more time
    ->asText();

echo $response->text;
```

#### Production Environment (Groq)

Groq is a **cloud-based LLM inference API** known for extremely fast response times. They host the models on their infrastructure.

**Setup:**
1. Create account at https://console.groq.com
2. Generate API key
3. Add to Laravel configuration

**Laravel Configuration (.env):**
```env
GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxxxxx
```

**Usage:**
```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Groq, 'llama-3.1-8b-instant')
    ->withSystemPrompt('You are a helpful customer support assistant.')
    ->withPrompt($userMessage)
    ->asText();

echo $response->text;
echo "Tokens used: " . $response->usage->totalTokens;
```

#### Streaming Responses (Real-time Chat)

For the chatbot widget, streaming provides a better UX by showing responses as they're generated.

**Controller:**
```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

public function chat(Request $request)
{
    return Prism::text()
        ->using(Provider::Groq, 'llama-3.1-8b-instant')
        ->withSystemPrompt($this->getSystemPrompt())
        ->withPrompt($request->input('message'))
        ->asEventStreamResponse(); // Returns SSE stream directly
}
```

**Vue Frontend:**
```javascript
const eventSource = new EventSource('/api/v1/widget/chat?message=' + encodeURIComponent(message));

eventSource.onmessage = (event) => {
    // Append each chunk to the chat UI
    chatMessage.value += event.data;
};

eventSource.addEventListener('done', () => {
    eventSource.close();
});
```

#### Provider Switching

One of Prism's key benefits is easy provider switching without code changes:

```php
// config/prism.php
return [
    'default_provider' => env('PRISM_PROVIDER', 'ollama'),
    
    'providers' => [
        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
        'groq' => [
            'api_key' => env('GROQ_API_KEY'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
        ],
    ],
];
```

```php
// Switch providers by changing one parameter
$provider = app()->environment('production') 
    ? Provider::Groq 
    : Provider::Ollama;

$model = app()->environment('production')
    ? 'llama-3.1-8b-instant'
    : 'gemma2:2b';

$response = Prism::text()
    ->using($provider, $model)
    ->withPrompt($message)
    ->asText();
```

#### Supported LLM Providers

Prism supports these providers out of the box:

| Provider | Type | Best For |
|----------|------|----------|
| Ollama | Local | Development, testing, privacy-sensitive deployments |
| Groq | Cloud | Production (fastest inference speeds) |
| OpenAI | Cloud | GPT-4, most capable models |
| Anthropic | Cloud | Claude models, long context |
| Mistral | Cloud | European provider, good performance |
| DeepSeek | Cloud | Cost-effective option |
| Google Gemini | Cloud | Multimodal capabilities |
| xAI | Cloud | Grok models |

#### Token Usage Tracking

Prism automatically tracks token usage, which is essential for billing:

```php
$response = Prism::text()
    ->using(Provider::Groq, 'llama-3.1-8b-instant')
    ->withPrompt($message)
    ->asText();

// Store for billing
UsageRecord::create([
    'tenant_id' => $tenant->id,
    'conversation_id' => $conversation->id,
    'prompt_tokens' => $response->usage->promptTokens,
    'completion_tokens' => $response->usage->completionTokens,
    'total_tokens' => $response->usage->totalTokens,
]);
```

#### Fallback Strategy

For production reliability, implement provider fallback:

```php
public function generateResponse(string $prompt): string
{
    try {
        // Primary: Groq (fastest)
        return Prism::text()
            ->using(Provider::Groq, 'llama-3.1-8b-instant')
            ->withPrompt($prompt)
            ->asText()
            ->text;
    } catch (\Exception $e) {
        // Fallback: OpenAI
        return Prism::text()
            ->using(Provider::OpenAI, 'gpt-4o-mini')
            ->withPrompt($prompt)
            ->asText()
            ->text;
    }
}
```

### 7.4 Data Models (Core Entities)

**Tenants**
- id, name, slug, domain
- owner_id, plan_id
- settings (JSON)
- created_at, updated_at

**Users**
- id, tenant_id, name, email, password
- role (owner, manager, agent)
- email_verified_at
- created_at, updated_at

**Conversations**
- id, tenant_id, visitor_id
- status (active, ended, escalated)
- lead_score
- metadata (JSON: browser, location, referrer)
- started_at, ended_at

**Messages**
- id, conversation_id
- role (visitor, assistant)
- content
- tokens_used
- confidence_score
- created_at

**Leads**
- id, tenant_id, conversation_id
- name, email, phone
- score, status
- custom_fields (JSON)
- created_at, updated_at

**KnowledgeItems**
- id, tenant_id
- type (document, faq, webpage, text)
- title, source_url
- content, content_hash
- status (processing, active, failed)
- created_at, updated_at

**KnowledgeChunks**
- id, knowledge_item_id
- content, embedding (vector)
- chunk_index
- created_at

**Subscriptions** (via Stripe/Cashier)

**UsageRecords**
- id, tenant_id
- type (tokens, conversations)
- quantity, period
- created_at

**AdminUsers**
- id, name, email, password
- role (super_admin, admin, support)
- email_verified_at
- created_at, updated_at

**AdminActivityLogs**
- id, admin_user_id
- action_type (impersonate, quota_change, plan_change, suspension, broadcast)
- target_type, target_id
- details (JSON: before/after values)
- ip_address
- created_at

**Broadcasts**
- id, admin_user_id
- title, content
- type (announcement, maintenance, urgent, promotional)
- target_segment (all, plan_starter, plan_business, plan_enterprise)
- channels (JSON: in_app, email)
- scheduled_at, sent_at
- created_at

**AdminNotifications**
- id, admin_user_id (nullable for all admins)
- type (quota_alert, system_error, new_signup, escalation)
- title, message
- data (JSON: related entity info)
- read_at
- created_at

**ClientNotifications**
- id, tenant_id, user_id (nullable for all team)
- type (quota_warning, escalation, announcement, team_activity)
- title, message
- data (JSON)
- read_at
- created_at

**ClientAlertSettings**
- id, tenant_id, user_id
- alert_type (quota_warning, daily_summary, escalation, unanswered_threshold)
- enabled
- threshold (nullable, e.g., 80 for 80% quota)
- channels (JSON: in_app, email)
- quiet_hours_start, quiet_hours_end
- created_at, updated_at

**CustomRoles** (for client teams)
- id, tenant_id
- name
- permissions (JSON)
- created_at, updated_at

**FailedJobs** (Laravel default, extended)
- id, uuid, connection, queue
- payload, exception
- failed_at
- tenant_id (added for filtering)

### 7.5 API Endpoints (Key)

**Authentication**
- POST /api/auth/register
- POST /api/auth/login
- POST /api/auth/logout
- POST /api/auth/forgot-password

**Widget (Public)**
- POST /api/v1/widget/init (validate API key, get config)
- POST /api/v1/widget/conversation (start conversation)
- POST /api/v1/widget/message (send message, get response)
- POST /api/v1/widget/lead (capture lead info)

**Client Dashboard (Authenticated - Tenant Scoped)**
- GET/POST/PUT/DELETE /api/v1/knowledge/*
- GET /api/v1/conversations
- GET /api/v1/conversations/{id}
- GET/PUT /api/v1/leads
- GET /api/v1/analytics/*
- GET/PUT /api/v1/settings/*
- GET/POST/DELETE /api/v1/team/*
- GET/PUT /api/v1/alerts/*
- GET /api/v1/notifications
- GET/PUT /api/v1/billing/*

**Admin Dashboard (Authenticated - Platform Admin Only)**
- GET /api/admin/dashboard (platform stats, token usage, alerts)
- GET /api/admin/clients (list all clients)
- GET /api/admin/clients/{id} (client detail)
- POST /api/admin/clients/{id}/impersonate (login as client)
- PUT /api/admin/clients/{id}/quota (adjust token quota)
- PUT /api/admin/clients/{id}/plan (change plan)
- PUT /api/admin/clients/{id}/status (suspend/reactivate)
- GET /api/admin/clients/{id}/conversations (client's conversations)
- GET /api/admin/clients/{id}/token-usage (detailed token breakdown)
- GET /api/admin/analytics/* (platform-wide analytics)
- GET /api/admin/token-usage (platform token consumption)
- GET /api/admin/token-usage/by-conversation (high-token conversations)
- GET /api/admin/system/health (LLM status, queue, errors)
- GET /api/admin/system/failed-jobs (failed background jobs)
- POST /api/admin/system/failed-jobs/{id}/retry (retry failed job)
- GET/POST /api/admin/broadcasts (create/list announcements)
- GET /api/admin/notifications (admin notification inbox)
- GET /api/admin/activity-logs (admin action audit log)

### 7.6 Security Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| SEC-01 | All API communication over HTTPS | P0 |
| SEC-02 | API key authentication for widget | P0 |
| SEC-03 | JWT/session authentication for dashboard | P0 |
| SEC-04 | Tenant data isolation (no cross-tenant access) | P0 |
| SEC-05 | Input sanitization (XSS, SQL injection prevention) | P0 |
| SEC-06 | Rate limiting on all endpoints | P0 |
| SEC-07 | Password hashing (bcrypt) | P0 |
| SEC-08 | CORS configuration for widget domains | P0 |
| SEC-09 | Audit logging for sensitive actions | P1 |
| SEC-10 | Data encryption at rest (database) | P2 |

---

## 8. Design Requirements

### 8.1 Widget Design Principles

- **Lightweight:** < 50KB initial load
- **Non-intrusive:** Doesn't block content, easy to dismiss
- **Accessible:** WCAG 2.1 AA compliant (keyboard navigation, screen reader support)
- **Responsive:** Works on all screen sizes
- **Fast:** First response visible within 3 seconds
- **Branded:** Reflects customer's brand colors/style

### 8.2 Dashboard Design Principles

- **Clean and functional:** Focus on tasks, minimal decoration
- **Data-forward:** Key metrics visible at a glance
- **Progressive disclosure:** Simple by default, advanced options available
- **Consistent:** Standard patterns for forms, tables, actions
- **Responsive:** Usable on tablet (desktop-first, but responsive)

---

## 9. Success Metrics & KPIs

### Product Metrics

| Metric | Definition | Target (6 months) |
|--------|------------|-------------------|
| Activation Rate | % of signups completing onboarding | > 60% |
| Time to Value | Days from signup to first lead captured | < 3 days |
| Daily Active Tenants | Tenants with ≥1 conversation/day | > 40% |
| Resolution Rate | % conversations without escalation | > 60% |
| Lead Capture Rate | % conversations capturing contact info | > 40% |
| NPS | Net Promoter Score | > 40 |

### Business Metrics

| Metric | Definition | Target (6 months) |
|--------|------------|-------------------|
| MRR | Monthly Recurring Revenue | [TBD] |
| Trial Conversion | % trials converting to paid | > 15% |
| Monthly Churn | % paying customers lost | < 5% |
| ARPU | Average Revenue Per User | [TBD] |
| CAC Payback | Months to recover acquisition cost | < 6 months |

---

## 10. Launch Plan

### Phase 1: Private Beta (Weeks 1-4)

- Deploy to 5-10 existing website clients
- Daily monitoring and feedback collection
- Bug fixes and critical improvements
- Validate core value proposition

**Exit Criteria:**
- 0 critical bugs
- > 50% of beta users actively using
- Clear feedback on top 3 improvements needed

### Phase 2: Public Beta (Weeks 5-8)

- Open signups with free trial
- WordPress.org plugin submission
- Basic marketing (existing client outreach, LinkedIn)
- Iterate based on wider feedback

**Exit Criteria:**
- 50+ active tenants
- < 2% error rate
- Onboarding completion > 50%

### Phase 3: General Availability (Week 9+)

- Full marketing launch
- Paid plans enforced
- Support processes established
- Ongoing iteration based on metrics

---

## 11. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| LLM response quality inconsistent | Medium | High | Extensive prompt engineering; fallback responses; continuous monitoring |
| Groq rate limits/outages | Medium | High | Implement retry logic; consider secondary provider; queue during outages |
| WordPress compatibility issues | Medium | Medium | Test across major themes/plugins; provide troubleshooting guide |
| Low trial conversion | Medium | High | Optimize onboarding; add in-app guidance; follow-up email sequences |
| Token costs exceed projections | Medium | Medium | Monitor closely; adjust plan limits; implement hard caps |
| Competitor launches similar product | Medium | Medium | Focus on local market knowledge; faster iteration; competitive pricing |
| Data privacy/GDPR concerns | Low | High | Clear privacy policy; data export; deletion tools; EU hosting option |

---

## 12. Future Roadmap (Post-V1)

### V1.1 (Month 2-3)
- Live chat handoff to human agents
- Basic CRM integrations (HubSpot, Zoho)
- Multi-language support (Dzongkha, Hindi)
- Improved analytics and reporting

### V1.2 (Month 4-6)
- WhatsApp/Facebook Messenger channels
- Advanced lead scoring with ML
- A/B testing for bot responses
- Webhook integrations

### V2.0 (Month 7-12)
- Voice support
- Custom AI training (fine-tuning)
- Enterprise SSO
- White-label reseller program

---

## 13. Open Questions

1. **Hosting decision:** Self-managed VPS vs. managed platform (Laravel Forge, Vapor)?
2. **Embedding model:** Which model for vector embeddings (local vs. API)?
3. **Compliance:** Any specific data residency requirements for Bhutan market?
4. **Pricing validation:** Should we test pricing with beta users before finalizing?
5. **WordPress.org listing:** Timeline and requirements for plugin directory approval?
6. **Branding:** Product name finalized?

---

## 14. Appendix

### A. Competitive Analysis

| Feature | Our Product | Nomind Bhutan | Tidio | Intercom |
|---------|-------------|---------------|-------|----------|
| WordPress Native | ✅ | ❓ | ✅ | Plugin |
| AI-Powered | ✅ | ✅ | ✅ | ✅ |
| Local Market Focus | ✅ | ✅ | ❌ | ❌ |
| Starting Price | $29/mo | [TBD] | $29/mo | $74/mo |
| Free Trial | ✅ | ❓ | ✅ | ✅ |
| Lead Scoring | ✅ | ❓ | Basic | ✅ |
| No-Code Setup | ✅ | ❓ | ✅ | ✅ |

### B. Glossary

- **RAG:** Retrieval-Augmented Generation; technique to ground LLM responses in specific knowledge
- **Tenant:** A customer account in the multi-tenant system
- **Token:** Unit of text processed by LLM (roughly ¾ of a word)
- **Vector Embedding:** Numerical representation of text for semantic search
- **Lead Score:** Numerical value indicating likelihood of conversion based on conversation signals

### C. References

- Laravel Documentation: https://laravel.com/docs
- Spatie Multitenancy: https://spatie.be/docs/laravel-multitenancy
- SQLite-vec: https://github.com/asg017/sqlite-vec
- Groq API: https://console.groq.com/docs
- WordPress Plugin Guidelines: https://developer.wordpress.org/plugins/
- Prism (Laravel LLM): https://prism.echolabs.dev/
- Vue 3: https://vuejs.org/guide/introduction.html
- Inertia.js: https://inertiajs.com/
- Tailwind CSS: https://tailwindcss.com/docs
- Laravel Streams: https://github.com/laravel/framework (native streaming support)

---

**Document Approval**

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Product Owner | | | |
| Engineering Lead | | | |
| Design Lead | | | |
| Business Stakeholder | | | |

---

*End of Document*