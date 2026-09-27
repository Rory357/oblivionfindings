import React, { useState } from "react";
import {
  Wrench,
  ClipboardCheck,
  ArrowUpRight,
  History,
  Package,
  UserRound,
  FileText,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { StatusBadge } from "@/components/ui/status-badge";
import {
  VehicleCollectionToggle as CollectionToggle,
  VehicleRecordCollection as RecordCollection,
} from "@/components/fleet-assets/vehicle-workspace/record-collection";

export function WorkRecords({
  progress,
  nextAction,
  readOnly,
  onOpen,
  onCheck,
  onReport,
  onHistory,
}: {
  progress: string;
  nextAction: string;
  readOnly: boolean;
  onOpen: () => void;
  onCheck: () => void;
  onReport: () => void;
  onHistory: () => void;
}) {
  const [view, setView] = useState<"list" | "cards">("list");
  return (
    <div className="vehicle-studio">
      <div className="studio-page record-workspace asset-record-workspace">
        <div className="studio-section-heading">
          <div>
            <span className="studio-eyebrow">ASSET RECORD · AS-104</span>
            <h2 className="text-section-title">Issues & Maintenance history</h2>
            <p>Original work, evidence and next actions.</p>
          </div>
          <CollectionToggle
            label="Maintenance"
            view={view}
            onChange={setView}
          />
          <Button disabled={readOnly} onClick={onReport}>
            Report a problem
          </Button>
        </div>
        <RecordCollection
          label="Maintenance records"
          view={view}
          identityWidth="1.25fr"
          columns={[
            { label: "Progress / owner" },
            { label: "Original source" },
            { label: "Next action", width: "1.5fr" },
          ]}
          empty={{ title: "No recorded work" }}
          records={[
            {
              id: "MW-271",
              name: "Brake assessment",
              subline: "MW-271 · Opened 24 Sep 2026",
              icon: Wrench,
              tone: "warning",
              fields: [
                <React.Fragment key="progress">
                  <StatusBadge variant="warning">{progress}</StatusBadge>
                  <small>Mara Ellis</small>
                </React.Fragment>,
                <React.Fragment key="check">
                  <button className="text-link" onClick={onCheck}>
                    CHK-882
                    <ArrowUpRight size={13} />
                  </button>
                  <small>Original check · v2</small>
                </React.Fragment>,
                <span key="next">{nextAction}</span>,
              ],
              onOpen,
              actions: [
                { label: "Open work", icon: Wrench, onClick: onOpen },
                {
                  label: "View original check",
                  icon: ClipboardCheck,
                  onClick: onCheck,
                },
              ],
              footer: {
                primary: "Mara Ellis",
                secondary: "Responsible owner · MW-271",
              },
            },
            {
              id: "MW-198",
              name: "Battery replacement",
              subline: "MW-198 · 3 Aug 2026",
              icon: Wrench,
              fields: [
                <React.Fragment key="progress">
                  <StatusBadge variant="neutral">Completed/Closed</StatusBadge>
                  <small>Mara Ellis</small>
                </React.Fragment>,
                <span key="source">Component AS-104-B1</span>,
                <span key="next">Historical work · No new action</span>,
              ],
              onOpen: onHistory,
              actions: [
                {
                  label: "View preserved work",
                  icon: History,
                  onClick: onHistory,
                },
              ],
              footer: {
                primary: "Mara Ellis",
                secondary: "Original component history",
              },
            },
          ]}
        />
      </div>
    </div>
  );
}

export function KitRecords({
  confirmed,
  readOnly,
  onReceipt,
  onSource,
  onHistory,
}: {
  confirmed: boolean[];
  readOnly: boolean;
  onReceipt: () => void;
  onSource: (title: string, body: string) => void;
  onHistory: () => void;
}) {
  const [view, setView] = useState<"list" | "cards">("list");
  return (
    <div className="vehicle-studio">
      <div className="studio-page record-workspace asset-record-workspace">
        <div className="studio-section-heading">
          <div>
            <span className="studio-eyebrow">ASSET RECORD · AS-104</span>
            <h2 className="text-section-title">Components & removable kit</h2>
            <p>Dispatch and receipt are recorded separately for every item.</p>
          </div>
          <CollectionToggle label="Kit" view={view} onChange={setView} />
          <Button disabled={readOnly} onClick={onReceipt}>
            <UserRound size={16} />
            Review receipt
          </Button>
        </div>
        <RecordCollection
          label="Kit contents"
          view={view}
          identityWidth="1.1fr"
          columns={[
            { label: "Relationship", width: "1.2fr" },
            { label: "Dispatch / receipt" },
            { label: "History / Finance", width: "1.1fr" },
          ]}
          empty={{ title: "No kit recorded" }}
          records={[
            ["Hoist frame", "AS-104", "Parent asset"],
            ["Battery pack", "AS-104-B2", "Independently maintained component"],
            ["Charger", "AS-104-K1", "Removable kit item"],
          ].map(([name, id, relationship], index) => {
            const detail = () =>
              onSource(
                name + " · " + id,
                relationship +
                  "\nDispatch: " +
                  (index === 2 ? "not recorded" : "recorded") +
                  "\nReceipt: " +
                  (confirmed[index]
                    ? "confirmed in this preview"
                    : "unconfirmed") +
                  "\nOriginal maintenance history and financial allocation remain source-linked. Moving the parent never confirms receipt of this item.",
              );
            return {
              id,
              name,
              subline: id,
              icon: Package,
              tone: confirmed[index] ? undefined : "warning",
              fields: [
                <span key="relationship">{relationship}</span>,
                <React.Fragment key="custody">
                  <StatusBadge
                    variant={confirmed[index] ? "success" : "warning"}
                  >
                    {confirmed[index]
                      ? "Receipt confirmed"
                      : "Receipt unconfirmed"}
                  </StatusBadge>
                  <small>
                    {index === 2
                      ? "Not recorded at dispatch"
                      : "Recorded at dispatch"}
                  </small>
                </React.Fragment>,
                <React.Fragment key="history">
                  <button
                    className="text-link"
                    onClick={index === 1 ? onHistory : detail}
                  >
                    {index === 1 ? "Replacement history" : "View source"}
                    <ArrowUpRight size={13} />
                  </button>
                  <small>Source allocation · No added total</small>
                </React.Fragment>,
              ],
              onOpen: detail,
              actions: [
                { label: "View item record", icon: FileText, onClick: detail },
                ...(index === 1
                  ? [
                      {
                        label: "Replacement history",
                        icon: History,
                        onClick: onHistory,
                      },
                    ]
                  : []),
                ...(!readOnly
                  ? [
                      {
                        label: "Review individual receipt",
                        icon: UserRound,
                        onClick: onReceipt,
                      },
                    ]
                  : []),
              ],
              footer: {
                primary: confirmed[index]
                  ? "Arrival confirmed"
                  : "Arrival needs confirmation",
                secondary: "Movement TR-104-08",
              },
            };
          })}
        />
      </div>
    </div>
  );
}
